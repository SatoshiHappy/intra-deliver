<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\UpdateDraft;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 申請(新規・一時保存から)(docs の 設計/ワークフロー/案件の操作/操作.md 3.5、同時実行と検査.md 5〜7 節、
 * 業務データとの接続/申請と書き込み.md 3 節・業務データとの接続/写しとつながる申請.md 5・9 節):
 * レコードができて案件が指し、履歴の行がその申請で書いた版 1(契機「申請」と申請のノードの名前 = 設計/業務データの入れ物/レコードの共通機能/
 * 更新履歴.md の 4.2 節)を指し、ノードが全部写り申請のノードが処理済み・承認 1 が承認待ち(展開どおり)、案件番号を採る。
 * 失敗(案件名・コメント・展開 0 人・業務データの入れ物の検証・403)では案件もレコードも残らず、展開 0 人では業務データの入れ物を呼ばない。
 */
final class ApplyCaseTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsWorkflowOperations;
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_applies_a_new_case(): void
    {
        $u0001 = $this->seededUser('u0001');

        $case = $this->applyAs($u0001, input: $this->projectInput(comment: '   '));

        $this->assertSame(
            [WorkflowCaseState::Active, null, $u0001->id, $this->departmentIdOf('tokyo-sales'), 0, null, 'プロジェクト計画の承認 新製品の企画'],
            [$case->state, $case->completion_reason, $case->user_id, $case->department_id, $case->revision, $case->draft_input, $case->name],
        );
        $this->assertNotNull($case->case_number);
        $this->assertNotNull($case->applied_at);
        $record = Record::query()->findOrFail($case->record_id);
        $this->assertSame([$this->projectFormModuleCode(), $u0001->id, $u0001->id], [RecordModule::query()->findOrFail($record->record_module_id)->code, $record->created_by_user_id, $record->updated_by_user_id]);
        $this->assertSame(
            [['apply', 'done', 'apply', []], ['approval1', 'waiting', null, [$this->seededUser('u0002')->id]], ['approval2', 'unreached', null, []]],
            $this->nodeStates($case),
        );
        $history = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->sole();
        $applyNode = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->where('position', 1)->sole();
        $this->assertSame([WorkflowAction::Apply, $applyNode->id, $u0001->id, null, null], [$history->action, $history->workflow_case_node_id, $history->user_id, $history->comment, $history->send_back_workflow_case_node_id]);
        $revision = RecordRevision::query()->findOrFail($history->record_revision_id);
        $this->assertSame(
            [$record->id, 1, RecordRevisionAction::Create, '申請', $applyNode->name, $u0001->id, null],
            [
                $revision->record_id, $revision->revision, $revision->action, $revision->origin_operation,
                $revision->origin_node_name, $revision->user_id, $revision->principal_user_id,
            ],
        );
    }

    /**
     * 履歴の行が指す版の写しの写像の位置は空でも {}(明細の行の references)、明細の行の並びは 0 行でも []。
     */
    public function test_the_snapshot_keeps_empty_maps_as_json_objects(): void
    {
        $withRow = $this->applyAs($this->seededUser('u0001'));
        $withoutRows = $this->applyAs($this->seededUser('u0001'), input: new ApplicationInput(null, null, new RecordInput($this->projectInput()->record->values, ['milestones' => []])));
        $formFields = RecordField::query()->whereRelation('recordModule', 'code', $this->projectFormModuleCode());
        $milestones = (string) $formFields->where('code', 'milestones')->sole()->id;

        $this->assertSame(['object', 'object', 'object', 'array', 'object', 'object'], $this->snapshotTypes($withRow, $milestones));
        $this->assertSame(['object', 'object', 'object', 'array', null, null], $this->snapshotTypes($withoutRows, $milestones));
    }

    public function test_applies_from_a_draft_on_the_same_row(): void
    {
        $u0001 = $this->seededUser('u0001');
        $caseId = app(CreateDraft::class)->execute($this->contextOf($u0001), self::FLOW_CODE, new DraftInput('下書き', null, ['name' => '下書き'], []));
        app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, new DraftInput('下書き 2', null, [], []));
        $this->assertNull(WorkflowCase::query()->findOrFail($caseId)->case_number);

        $applied = app(ApplyCase::class)->executeFromDraft($this->contextOf($u0001), $caseId, 1, $this->projectInput(name: '  自分の案件名 ', comment: 'よろしくお願いします'), $this->operationDay()->toDateString());

        $case = WorkflowCase::query()->findOrFail($caseId);
        $this->assertSame($caseId, $applied->caseId);
        $this->assertSame([WorkflowCaseState::Active, null, 2, '自分の案件名'], [$case->state, $case->draft_input, $case->revision, $case->name]);
        $this->assertNotNull($case->case_number);
        $this->assertNotNull($case->record_id);
        $this->assertSame('よろしくお願いします', WorkflowCaseHistory::query()->where('workflow_case_id', $caseId)->sole()->comment);
        $this->assertSame('waiting', $this->nodeStates($case)[1][1]);
    }

    /**
     * 案件名の既定(<フロー名> <表示の項目の値>)は 1000 文字を超える分を切る。
     */
    public function test_the_default_name_is_cut_at_1000_characters(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'), input: $this->projectInput(['name' => str_repeat('あ', 1000)]));

        $this->assertSame(mb_substr('プロジェクト計画の承認 '.str_repeat('あ', 1000), 0, 1000), $case->name);
        $this->assertSame(1000, mb_strlen((string) $case->name));
    }

    /**
     * 担当組織 = 申請者のカレント組織(選んだ組織。選ばなければ主所属)。u0009 = 総務部(主)・人事部(兼務)。
     */
    public function test_the_department_is_the_current_department_of_the_applicant(): void
    {
        $u0009 = $this->seededUser('u0009');

        $selected = WorkflowCase::query()->findOrFail(app(ApplyCase::class)->execute($this->contextOf($u0009, $this->departmentIdOf('human-resources')), self::FLOW_CODE, $this->projectInput(), $this->operationDay()->toDateString())->caseId);
        $main = $this->applyAs($u0009);

        $this->assertSame([$this->departmentIdOf('human-resources'), $this->departmentIdOf('general-affairs')], [$selected->department_id, $main->department_id]);
    }

    /**
     * @return array<string, array{string|null, string|null, array<string, string>}>
     */
    public static function invalidNamesAndComments(): array
    {
        return [
            '申請名が 1001 文字' => [str_repeat('あ', 1001), null, ['name' => '申請名は 1000 文字以内で入力してください']],
            '申請名の改行' => ["1 行目\n2 行目", null, ['name' => '申請名に改行を入力できません']],
            '申請名の U+0000' => ["名\0前", null, ['name' => '申請名に使用できない文字が含まれています']],
            'コメントが 4001 文字' => [null, str_repeat('あ', 4001), ['comment' => 'コメントは 4000 文字以内で入力してください']],
            'コメントの U+0000' => [null, "コ\0メント", ['comment' => 'コメントに使用できない文字が含まれています']],
            '両方' => ["a\rb", "コ\0メント", ['name' => '申請名に改行を入力できません', 'comment' => 'コメントに使用できない文字が含まれています']],
        ];
    }

    /**
     * 案件名・コメントの誤りは業務データの入れ物より先に返り、何も残らない(1000・4000 文字ちょうどは通る = 別のテスト)。
     *
     * @param  array<string, string>  $expected
     */
    #[DataProvider('invalidNamesAndComments')]
    public function test_rejects_an_invalid_name_or_comment(?string $name, ?string $comment, array $expected): void
    {
        $counts = $this->workflowCounts();
        $input = $this->projectInput(['name' => null], $name, $comment);

        $this->assertSame($expected, $this->validationErrors(fn () => $this->applyAs($this->seededUser('u0001'), input: $input)));
        $this->assertSame($counts, $this->workflowCounts());
    }

    public function test_a_name_of_1000_and_a_comment_of_4000_characters_are_accepted(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'), input: $this->projectInput(name: str_repeat('あ', 1000), comment: str_repeat('い', 4000)));

        $this->assertSame(1000, mb_strlen((string) $case->name));
        $this->assertSame(4000, mb_strlen((string) WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->sole()->comment));
    }

    /**
     * 案件名の前後の U+0000 は前後の空白と同じく除かれて通る(入口の TrimStrings と同じ変換 = Str::trim の既定の文字集合。中の U+0000 は 422 = 上の表)。
     */
    public function test_a_leading_nul_in_the_name_is_trimmed(): void
    {
        $this->assertSame('名前', $this->applyAs($this->seededUser('u0001'), input: $this->projectInput(name: "\0名前"))->name);
    }

    /**
     * 業務データの入れ物の検証の失敗はそのキーのまま伝わり、403 も含めて案件もレコードも残らない(一時保存からは一時保存のまま)。
     */
    public function test_a_record_validation_failure_or_403_leaves_nothing(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draftId = app(CreateDraft::class)->execute($this->contextOf($u0001), self::FLOW_CODE, new DraftInput(null, null, [], []));
        $counts = $this->workflowCounts();
        $invalid = $this->projectInput(['name' => null]);

        $this->assertSame(['values.name'], array_keys($this->validationErrors(fn () => $this->applyAs($u0001, input: $invalid))));
        $this->assertSame(['values.name'], array_keys($this->validationErrors(fn () => app(ApplyCase::class)->executeFromDraft($this->contextOf($u0001), $draftId, 0, $invalid, $this->operationDay()->toDateString()))));
        $this->assertSame($counts, $this->workflowCounts());

        $this->deny($this->projectFormModuleCode(), RecordModuleAction::Create);

        $this->assertThrows(fn () => $this->applyAs($u0001), AuthorizationException::class, 'このフローを申請できません');
        $this->assertThrows(fn () => app(ApplyCase::class)->executeFromDraft($this->contextOf($u0001), $draftId, 0, $this->projectInput(), $this->operationDay()->toDateString()), AuthorizationException::class, 'このフローを申請できません');
        $this->assertSame($counts, $this->workflowCounts());
        $draft = WorkflowCase::query()->findOrFail($draftId);
        $this->assertSame([WorkflowCaseState::Draft, 0], [$draft->state, $draft->revision]);
    }

    /**
     * 承認 1 の展開が 0 人なら 422 case。業務データの入れ物の口は展開の前に呼ぶ(ルートの深さ/案件の操作/操作.md 7 節の (b))が、同じトランザクションで巻き戻り、
     * レコード・案件・ノード・履歴は何も残らない。
     */
    public function test_zero_targets_are_rejected_after_the_record_module_and_nothing_remains(): void
    {
        $this->createFlow('to_retired', '退職者へのフロー', approvals: [[$this->userCondition('u0014')]]);
        $counts = $this->workflowCounts();
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = $query->sql;
        });

        $errors = $this->validationErrors(fn () => $this->applyAs($this->seededUser('u0001'), 'to_retired'));

        $this->assertSame(['case' => '承認1の処理担当者がいないため、この操作はできません'], $errors);
        $this->assertNotSame([], array_values(array_filter($log, fn (string $sql) => str_contains($sql, 'insert into "records"'))));
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 展開を申請を通して 1 件ずつ: 兼務の人は兼務の組織の指定にも入る(u0009 の人事部)、d に退職した人は入らない(経理部の u0014)、
     * 本人の判定で合う人は必ず入る(東京営業課の u0001・u0002)。
     */
    public function test_the_first_approval_is_expanded_on_the_date(): void
    {
        $byDepartment = fn (string $code) => [[new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf($code))]];
        $this->createFlow('to_hr', '人事部へ', approvals: $byDepartment('human-resources'));
        $this->createFlow('to_accounting', '経理部へ', approvals: $byDepartment('accounting'));
        $this->createFlow('to_tokyo_sales', '東京営業課へ', approvals: $byDepartment('tokyo-sales'));
        $u0001 = $this->seededUser('u0001');
        $ids = fn (string ...$codes) => array_map(fn (string $code) => $this->seededUser($code)->id, $codes);

        $this->assertSame($ids('u0009'), $this->nodeStates($this->applyAs($u0001, 'to_hr'))[1][3]);
        $this->assertSame($ids('u0010'), $this->nodeStates($this->applyAs($u0001, 'to_accounting'))[1][3]);
        $tokyoSales = $this->applyAs($u0001, 'to_tokyo_sales');
        $subjectGroupId = WorkflowCaseNode::query()->where('workflow_case_id', $tokyoSales->id)->where('position', 2)->sole()->authz_subject_group_id ?? $this->fail('承認 1 は対象者で指定のノード');

        foreach (['u0001', 'u0002'] as $code) {
            $this->assertSame([$subjectGroupId], app(SubjectGroupMembership::class)->matchingIds(new Requester($this->seededUser($code)->id, null), [$subjectGroupId], $this->operationDay()));
        }

        $this->assertSame($ids('u0001', 'u0002'), $this->nodeStates($tokyoSales)[1][3]);
    }

    /**
     * 案件番号: 一時保存は持たず、申請で採り、後の申請ほど大きい(具体の値に頼らない)。
     */
    public function test_case_numbers_are_taken_on_applying(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draftId = app(CreateDraft::class)->execute($this->contextOf($u0001), self::FLOW_CODE, new DraftInput(null, null, [], []));
        $this->assertNull(WorkflowCase::query()->findOrFail($draftId)->case_number);

        $first = $this->applyAs($u0001);
        app(ApplyCase::class)->executeFromDraft($this->contextOf($u0001), $draftId, 0, $this->projectInput(), $this->operationDay()->toDateString());

        $this->assertGreaterThan((int) $first->case_number, (int) WorkflowCase::query()->findOrFail($draftId)->case_number);
    }

    /**
     * 一時保存からの申請を同じ改訂番号で 2 回(応答を失った再送)= 2 回目は 422 case で、レコードも履歴も増えない。
     */
    public function test_resending_an_application_from_a_draft_is_rejected_by_the_revision(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draftId = app(CreateDraft::class)->execute($this->contextOf($u0001), self::FLOW_CODE, new DraftInput(null, null, [], []));
        app(ApplyCase::class)->executeFromDraft($this->contextOf($u0001), $draftId, 0, $this->projectInput(), $this->operationDay()->toDateString());
        $counts = $this->workflowCounts();
        $case = WorkflowCase::query()->findOrFail($draftId);

        $errors = $this->validationErrors(fn () => app(ApplyCase::class)->executeFromDraft($this->contextOf($u0001), $draftId, 0, $this->projectInput(), $this->operationDay()->toDateString()));

        $this->assertSame(['case' => '画面を表示した後に申請が変更されました。再表示してください'], $errors);
        $this->assertSame($counts, $this->workflowCounts());
        $this->assertSame([1, $case->case_number], [$case->refresh()->revision, $case->case_number]);
    }

    /**
     * ゲストの申請は 403(一時保存からは閲覧できないので 404)、無いフローは 404。
     */
    public function test_a_guest_gets_403_and_a_missing_flow_is_not_found(): void
    {
        $draftId = app(CreateDraft::class)->execute($this->contextOf($this->seededUser('u0001')), self::FLOW_CODE, new DraftInput(null, null, [], []));
        $counts = $this->workflowCounts();

        $this->assertThrows(fn () => app(ApplyCase::class)->execute($this->contextOf(null), self::FLOW_CODE, $this->projectInput(), $this->operationDay()->toDateString()), AuthorizationException::class, 'このフローを申請できません');
        $this->assertThrows(fn () => app(ApplyCase::class)->executeFromDraft($this->contextOf(null), $draftId, 0, $this->projectInput(), $this->operationDay()->toDateString()), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->applyAs($this->seededUser('u0001'), 'no_such_flow'), ModelNotFoundException::class);
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 履歴の行が指す版の写しの jsonb_typeof: values・references・details・明細の項目・その 1 行目の values・references。
     *
     * @return list<string|null>
     */
    private function snapshotTypes(WorkflowCase $case, string $detailsFieldId): array
    {
        $row = DB::selectOne(
            "select jsonb_typeof(s->'values') as v, jsonb_typeof(s->'references') as r, jsonb_typeof(s->'details') as d, jsonb_typeof(s->'details'->?) as f,
                jsonb_typeof(s->'details'->?->0->'values') as rv, jsonb_typeof(s->'details'->?->0->'references') as rr
             from (select rev.snapshot as s from workflow_case_histories as wh
                 join record_revisions as rev on rev.id = wh.record_revision_id where wh.workflow_case_id = ?) as h",
            [$detailsFieldId, $detailsFieldId, $detailsFieldId, $case->id],
        );

        return [$row->v, $row->r, $row->d, $row->f, $row->rv, $row->rr];
    }
}
