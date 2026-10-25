<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseInputRules;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DeleteDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\JsonObjectMaps;
use App\Actions\Workflow\UpdateDraft;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseOperationDecider;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 一時保存の新規・上書き・削除(docs の 設計/ワークフロー/案件の操作/操作.md 3.4、同時実行と検査.md 4・6 節、業務データとの接続/申請と書き込み.md 2 節):
 * 入力は検証せずに保ち(案件名・コメントは前後の空白を除く)、大きさ(4,194,304 バイト)と使えない文字だけを 422 draft で拒む。空の写像は {}。
 * 改訂番号は新規 0・上書きで +1、古い値は 422 case。申請できない人(ゲストを含む)は新規・上書きが 403(行の can の「開く」も偽)、削除は申請できなくても通る。
 */
final class DraftTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsWorkflowOperations;
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_creates_a_draft_keeping_the_input_without_validation(): void
    {
        $u0001 = $this->seededUser('u0001');

        $caseId = $this->createDraft($u0001, new DraftInput('  名前  ', ' ', ['name' => '', 'budget' => 'たくさん', 'unknown' => [1, 2]], ['milestones' => [['values' => ['name' => '要件']]]]));

        $case = WorkflowCase::query()->findOrFail($caseId);
        $this->assertSame([WorkflowCaseState::Draft, $u0001->id, 0, null, null, null, null, null], [$case->state, $case->user_id, $case->revision, $case->name, $case->case_number, $case->record_id, $case->department_id, $case->applied_at]);
        $this->assertSameDraft(
            ['name' => '名前', 'comment' => null, 'values' => ['name' => null, 'budget' => 'たくさん', 'unknown' => [1, 2]], 'details' => ['milestones' => [['values' => ['name' => '要件']]]], 'base_date' => null],
            $case,
        );
        $this->assertSame(0, WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->count());
    }

    public function test_overwrites_a_draft_and_increments_the_revision(): void
    {
        $u0001 = $this->seededUser('u0001');
        $caseId = $this->createDraft($u0001, new DraftInput('前', null, ['name' => '前'], []));

        app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, new DraftInput('後', 'メモ', ['name' => '後'], []));

        $case = WorkflowCase::query()->findOrFail($caseId);
        $this->assertSame(1, $case->revision);
        $this->assertSameDraft(['name' => '後', 'comment' => 'メモ', 'values' => ['name' => '後'], 'details' => [], 'base_date' => null], $case);
    }

    public function test_deletes_a_draft(): void
    {
        $u0001 = $this->seededUser('u0001');
        $caseId = $this->createDraft($u0001);
        app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, $this->draft());

        app(DeleteDraft::class)->execute($this->contextOf($u0001), $caseId, 1);

        $this->assertFalse(WorkflowCase::query()->whereKey($caseId)->exists());
    }

    /**
     * 空の写像(values・details・行の values)は JSON の {}、明細の行の並びは 0 行でも []。DB の検査制約は最上位しか見ないので SQL で中を確かめる。
     */
    public function test_empty_maps_are_stored_as_json_objects(): void
    {
        $empty = $this->createDraft($this->seededUser('u0001'), new DraftInput(null, null, [], []));
        $rows = $this->createDraft($this->seededUser('u0001'), new DraftInput(null, null, [], ['milestones' => [['values' => []]], 'others' => []]));

        $this->assertSame(['object', 'object', null, null], $this->jsonTypes($empty));
        $this->assertSame(['object', 'object', 'array', 'object'], $this->jsonTypes($rows));
        $this->assertSame('array', DB::scalar("select jsonb_typeof(draft_input->'details'->'others') from workflow_cases where id = ?", [$rows]));
    }

    /**
     * 上限ちょうど(json_encode(JSON_UNESCAPED_UNICODE) のバイト数 = 4,194,304)は通り、1 バイト超えると 422 draft。新規も上書きも同じ。
     */
    public function test_the_size_limit_is_inclusive(): void
    {
        $u0001 = $this->seededUser('u0001');
        $base = strlen((string) json_encode(JsonObjectMaps::draftInput(null, null, ['memo' => ''], [], null), JSON_UNESCAPED_UNICODE));
        $fits = new DraftInput(null, null, ['memo' => str_repeat('a', CaseInputRules::DRAFT_MAX_BYTES - $base)], []);
        $over = new DraftInput(null, null, ['memo' => str_repeat('a', CaseInputRules::DRAFT_MAX_BYTES - $base + 1)], []);

        $caseId = $this->createDraft($u0001, $fits);

        $this->assertSame(['draft' => '一時保存の内容が大きすぎます'], $this->validationErrors(fn () => $this->createDraft($u0001, $over)));
        $this->assertSame(['draft' => '一時保存の内容が大きすぎます'], $this->validationErrors(fn () => app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, $over)));
        $this->assertSame(0, WorkflowCase::query()->findOrFail($caseId)->revision);
    }

    /**
     * @return array<string, array{DraftInput}>
     */
    public static function inputsWithInvalidCharacters(): array
    {
        return [
            '値の U+0000' => [new DraftInput(null, null, ['name' => "a\0b"], [])],
            'キーの U+0000' => [new DraftInput(null, null, ["na\0me" => 'a'], [])],
            '明細の行の U+0000' => [new DraftInput(null, null, [], ['milestones' => [['values' => ['name' => "a\0b"]]]])],
            '案件名の U+0000' => [new DraftInput("a\0b", null, [], [])],
            '値の不正な UTF-8' => [new DraftInput(null, null, ['name' => "a\xFFb"], [])],
            'コメントの不正な UTF-8' => [new DraftInput(null, "a\xC3", [], [])],
        ];
    }

    #[DataProvider('inputsWithInvalidCharacters')]
    public function test_rejects_invalid_characters_and_leaves_nothing(DraftInput $input): void
    {
        $u0001 = $this->seededUser('u0001');
        $caseId = $this->createDraft($u0001);
        $counts = $this->workflowCounts();

        $this->assertSame(['draft' => '一時保存の内容に使用できない文字が含まれています'], $this->validationErrors(fn () => $this->createDraft($u0001, $input)));
        $this->assertSame(['draft' => '一時保存の内容に使用できない文字が含まれています'], $this->validationErrors(fn () => app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, $input)));
        $this->assertSame($counts, $this->workflowCounts());
        $this->assertSame(0, WorkflowCase::query()->findOrFail($caseId)->revision);
    }

    /**
     * 値は入口と同じ変換(前後の空白・U+0000 を除き空なら null)の後のまま保つ: 末尾の U+0000 は除かれて通り、中の U+0000 は 422 draft。
     */
    public function test_a_trailing_nul_in_a_value_is_trimmed_and_a_middle_one_is_rejected(): void
    {
        $u0001 = $this->seededUser('u0001');

        $caseId = $this->createDraft($u0001, new DraftInput(null, null, ['name' => "下書き\0"], ['milestones' => [['values' => ['name' => " 要件\0"]]]]));

        $this->assertSameDraft(['name' => null, 'comment' => null, 'values' => ['name' => '下書き'], 'details' => ['milestones' => [['values' => ['name' => '要件']]]], 'base_date' => null], WorkflowCase::query()->findOrFail($caseId));
        $this->assertSame(['draft' => '一時保存の内容に使用できない文字が含まれています'], $this->validationErrors(fn () => $this->createDraft($u0001, new DraftInput(null, null, ['name' => "下\0書き"], []))));
    }

    /**
     * 上書きは申請できる人の判定(申請のノードの読み込み)を 1 回だけ行い、業務モジュールの update は判定しない(判定の入口が 1 つ = 403 は閉包だけ)。
     */
    public function test_overwriting_judges_the_applicant_once(): void
    {
        $u0001 = $this->seededUser('u0001');
        $caseId = $this->createDraft($u0001);

        $sql = $this->recordedSql(fn () => app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, $this->draft()));

        $this->assertSame([1, 0], [count(preg_grep(self::APPLY_NODE_SQL, $sql) ?: []), count(preg_grep(self::UPDATE_JUDGEMENT_SQL, $sql) ?: [])]);
    }

    /**
     * 古い改訂番号の上書き・削除は 422 case で何も変わらない(上書きの再送の 2 回目を含む)。
     */
    public function test_a_stale_revision_is_rejected(): void
    {
        $u0001 = $this->seededUser('u0001');
        $caseId = $this->createDraft($u0001);
        app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, new DraftInput('1 回目', null, [], []));
        $stale = '画面を表示した後に申請が変更されました。再表示してください';

        $this->assertSame(['case' => $stale], $this->validationErrors(fn () => app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, new DraftInput('再送', null, [], []))));
        $this->assertSame(['case' => $stale], $this->validationErrors(fn () => app(DeleteDraft::class)->execute($this->contextOf($u0001), $caseId, 0)));

        $case = WorkflowCase::query()->findOrFail($caseId);
        $this->assertSame([1, '1 回目'], [$case->revision, $case->draft_input['name'] ?? null]);
    }

    /**
     * 申請のノードの指定に合わない人は新規が 403。業務モジュールの create を禁止すると、本人の一時保存の上書きは 403、削除は通る。
     */
    public function test_a_person_who_cannot_apply_gets_403_but_can_still_delete(): void
    {
        $this->createFlow('only_u0001', '申請者を限ったフロー', applicants: [$this->userCondition('u0001')]);
        $u0001 = $this->seededUser('u0001');
        $caseId = $this->createDraft($u0001);

        $this->assertThrowsMessage(fn () => app(CreateDraft::class)->execute($this->contextOf($this->seededUser('u0003')), 'only_u0001', $this->draft()));

        $this->deny($this->projectFormModuleCode(), RecordModuleAction::Create);

        $this->assertThrowsMessage(fn () => $this->createDraft($u0001));
        $this->assertThrowsMessage(fn () => app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, $this->draft()));
        $this->assertSame(0, WorkflowCase::query()->findOrFail($caseId)->revision);
        $requester = new Requester($u0001->id, null);
        $situations = app(CaseOperationDecider::class)->situationsOf($requester, $this->operationDay(), [WorkflowCase::query()->findOrFail($caseId)]);
        $can = app(CaseOperationDecider::class)->rowCansOf($requester, $this->operationDay(), array_values($situations))[$caseId];
        $this->assertSame([false, true], [$can['editDraft'], $can['deleteDraft']]);

        app(DeleteDraft::class)->execute($this->contextOf($u0001), $caseId, 0);

        $this->assertFalse(WorkflowCase::query()->whereKey($caseId)->exists());
    }

    /**
     * ゲストの新規は 403。ほかの人の一時保存とゲストの上書き・削除は閲覧できないので 404。無いフローは 404。
     */
    public function test_a_guest_gets_403_and_a_draft_of_another_person_is_not_found(): void
    {
        $caseId = $this->createDraft($this->seededUser('u0001'));

        $this->assertThrowsMessage(fn () => app(CreateDraft::class)->execute($this->contextOf(null), self::FLOW_CODE, $this->draft()));
        $this->assertThrows(fn () => app(CreateDraft::class)->execute($this->contextOf($this->seededUser('u0001')), 'no_such_flow', $this->draft()), ModelNotFoundException::class);

        foreach ([null, $this->seededUser('u0002')] as $other) {
            $this->assertThrows(fn () => app(UpdateDraft::class)->execute($this->contextOf($other), $caseId, 0, $this->draft()), ModelNotFoundException::class);
            $this->assertThrows(fn () => app(DeleteDraft::class)->execute($this->contextOf($other), $caseId, 0), ModelNotFoundException::class);
        }

        $this->assertTrue(WorkflowCase::query()->whereKey($caseId)->exists());
    }

    /**
     * 一時保存のルートの書き込みが一時保存でない案件を指すと、404 でなく 422 case(状態 × 役割の表)。
     */
    public function test_draft_operations_on_an_applied_case_are_422(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001);
        $notPermitted = ['case' => 'この申請は今この操作をできません'];

        $this->assertSame($notPermitted, $this->validationErrors(fn () => app(UpdateDraft::class)->execute($this->contextOf($u0001), $case->id, $case->revision, $this->draft())));
        $this->assertSame($notPermitted, $this->validationErrors(fn () => app(DeleteDraft::class)->execute($this->contextOf($u0001), $case->id, $case->revision)));
        $this->assertSame(WorkflowCaseState::Active, $case->refresh()->state);
    }

    /**
     * 保存した draft_input(jsonb はキーの順を保たないので、連想配列のキーを並べ替えて比べる)。
     *
     * @param  array<string, mixed>  $expected
     */
    private function assertSameDraft(array $expected, WorkflowCase $case): void
    {
        $this->assertSame($this->sortedKeys($expected), $this->sortedKeys($case->draft_input));
    }

    private function createDraft(User $user, ?DraftInput $input = null): int
    {
        return app(CreateDraft::class)->execute($this->contextOf($user), self::FLOW_CODE, $input ?? $this->draft());
    }

    private function draft(): DraftInput
    {
        return new DraftInput('案件名', null, ['name' => '下書き'], []);
    }

    /**
     * @param  Closure(): mixed  $action
     */
    private function assertThrowsMessage(Closure $action): void
    {
        $this->assertThrows($action, AuthorizationException::class, 'このフローを申請できません');
    }

    /**
     * draft_input の values・details・details の milestones・その 1 行目の values の jsonb_typeof。
     *
     * @return list<string|null>
     */
    private function jsonTypes(int $caseId): array
    {
        $row = DB::selectOne(
            "select jsonb_typeof(draft_input->'values') as v, jsonb_typeof(draft_input->'details') as d, jsonb_typeof(draft_input->'details'->'milestones') as m, jsonb_typeof(draft_input->'details'->'milestones'->0->'values') as r from workflow_cases where id = ?",
            [$caseId],
        );

        return [$row->v, $row->d, $row->m, $row->r];
    }
}
