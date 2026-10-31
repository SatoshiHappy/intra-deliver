<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Queries\Workflow\CaseEntryGate;
use App\Queries\Workflow\CaseNumbers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 申請・一時保存・再申請の画面の読み取り(docs の 設計/ワークフロー/画面/申請と案件.md 3 節の Props、開けないときの順 = 1.1、
 * 業務データとの接続/申請と書き込み.md 2 節・写しとつながる申請.md 9 節):
 * 3 つのモードの形、404・403 の順、再申請ができない状態・役割の null、承認 1 の今の展開(0 人なら空)、初期値(一時保存 = 今の定義で読み直し・行の id なし、
 * 再申請 = 今のレコード・行の id あり)、閲覧できない案件では業務データの入れ物の表を読まない。フローと案件はテストで作る(初期データの定義・案件を見ない)。
 */
final class ApplicationFormQueryTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;
    use UsesSeededRecords;

    /** 業務データの入れ物の表(records・record_*)を読み書きする SQL */
    private const RECORDS_SQL = '/(from|join|update|into)\s+"record(s|_[a-z_]+)"/';

    public function test_the_new_form_carries_the_flow_the_module_and_the_first_approval(): void
    {
        $this->createFlow('wf_form', 'フォームの見本');

        $form = $this->formQuery()->create($this->contextOf($this->seededUser('u0001')), 'wf_form');

        $this->assertSame(
            [
                'new', ['code' => 'wf_form', 'name' => 'フォームの見本', 'description' => null], null,
                $this->projectFormModuleCode(), null, 'name',
            ],
            [$form['mode'], $form['flow'], $form['case'], $form['module']['code'], $form['initial'], $form['titleField']],
        );
        $this->assertSame(['version' => 1, 'noVersionMessage' => null, 'departmentName' => '東京営業課', 'nodes' => [['name' => '承認1', 'targets' => [$this->person('u0002')], 'blockedReason' => null]], 'dependsOnContent' => false], $form['next']);
        // 申請書の業務モジュールは見出しを持たない(頭に全項目。明細は見出しに属さない)
        $this->assertSame([
            [null, ['project', 'name', 'department', 'manager', 'status', 'start_date', 'end_date', 'budget',
                'summary']],
        ], array_map(fn (array $group) => [$group['name'], $group['fieldCodes']], $form['headings']));
        $this->assertSame([1000, 4000], [$form['nameMaxLength'], $form['commentMaxLength']]);
        $this->assertNull($form['recordId']);
        $this->assertSame(['2026-10-13', ['min' => '2025-10-13', 'max' => '2027-10-13']], [$form['baseDate'], $form['baseDateRange']]);
    }

    /**
     * 新規の画面は、フローが無ければ 404、申請できる人でなければ(ゲストを含む)403。
     */
    public function test_the_new_form_is_not_found_without_the_flow_and_forbidden_to_who_cannot_apply(): void
    {
        $this->createFlow('wf_only_b', 'B だけ', applicants: [$this->userCondition('u0002')]);
        $u0001 = $this->contextOf($this->seededUser('u0001'));

        $this->assertThrows(fn () => $this->formQuery()->create($u0001, 'wf_missing'), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->formQuery()->create($u0001, 'wf_only_b'), AuthorizationException::class, CaseEntryGate::NOT_APPLICABLE_MESSAGE);
        $this->assertThrows(fn () => $this->formQuery()->create($this->contextOf(null), 'wf_only_b'), AuthorizationException::class, CaseEntryGate::NOT_APPLICABLE_MESSAGE);
    }

    /**
     * 承認 1 の処理対象者が d に 0 人(入社前の u0015 だけ)なら next.nodes の targets は空(画面は申請を押せなくする。例外にしない)。
     */
    public function test_the_next_node_has_no_targets_when_nobody_is_expanded(): void
    {
        $this->createFlow('wf_nobody', '誰もいない', approvals: [[$this->userCondition('u0015')]]);

        $this->assertSame([['name' => '承認1', 'targets' => [], 'blockedReason' => null]], $this->formQuery()->create($this->contextOf($this->seededUser('u0001')), 'wf_nobody')['next']['nodes']);
    }

    /**
     * 一時保存の画面の初期値は、一時保存の入力を今の定義で読み直したもの(定義に無いキーを捨て、型に合わない値は未設定、明細の行は id を持たない)と案件名・コメント。
     */
    public function test_the_draft_form_reads_the_draft_input_with_the_current_definition(): void
    {
        $this->createFlow('wf_form', 'フォームの見本');
        $u0001 = $this->seededUser('u0001');
        $caseId = app(CreateDraft::class)->execute($this->contextOf($u0001), 'wf_form', new DraftInput('下書きの案件', 'メモ', ['name' => '新しい計画', 'budget' => 'abc', 'nope' => 1], [
            'milestones' => [['values' => ['name' => '要件の整理', 'due_date' => '2026-11-30']]],
        ]));

        $form = $this->formQuery()->draft($this->contextOf($u0001), $caseId) ?? $this->fail('一時保存の画面が null');
        $initial = $form['initial'] ?? [];

        $this->assertSame(['draft', ['id' => $caseId, 'number' => null, 'revision' => 0], null], [$form['mode'], $form['case'], $form['recordId']]);
        $this->assertSame(['下書きの案件', 'メモ', '新しい計画'], [$initial['name'] ?? null, $initial['comment'] ?? null, $initial['values']['name'] ?? null]);
        $this->assertSame([true, null, false], [array_key_exists('budget', $initial['values'] ?? []), $initial['values']['budget'] ?? null, array_key_exists('nope', $initial['values'] ?? [])]);
        $this->assertSame([['values' => ['name' => '要件の整理', 'due_date' => '2026-11-30', 'effort' => null, 'is_done' => false]]], $initial['details']['milestones'] ?? null);
        $this->assertSame([['name' => '承認1', 'targets' => [$this->person('u0002')], 'blockedReason' => null]], $form['next']['nodes']);
    }

    /**
     * 一時保存の画面は、案件が見えない(他人の一時保存)・無いと 404、見えるが一時保存でないと null(入口が詳細へ移す)、申請できる人でなくなったら 403(閲覧の判定の後)。
     */
    public function test_the_draft_form_is_not_found_for_others_null_for_non_drafts_and_forbidden_to_who_cannot_apply(): void
    {
        $this->createFlow('wf_only_a', 'A だけ', applicants: [$this->userCondition('u0001')]);
        $u0001 = $this->seededUser('u0001');
        $draftId = app(CreateDraft::class)->execute($this->contextOf($u0001), 'wf_only_a', new DraftInput(null, null, [], []));
        $applied = $this->applyAs($u0001, 'wf_only_a');

        foreach ([[$this->seededUser('u0002'), $draftId], [$this->seededUser('u0008'), $applied->id], [$u0001, 0]] as [$viewer, $caseId]) {
            $this->assertThrows(fn () => $this->formQuery()->draft($this->contextOf($viewer), $caseId), ModelNotFoundException::class);
        }

        $this->assertNull($this->formQuery()->draft($this->contextOf($u0001), $applied->id));

        $applyNode = WorkflowRouteNode::query()->whereRelation('workflowFlowVersion.workflowFlow', 'code', 'wf_only_a')->where('position', 1)->sole();
        AuthzSubjectCondition::query()->where('authz_subject_group_id', $applyNode->authz_subject_group_id)->update(['user_id' => $this->seededUser('u0002')->id]);
        $this->assertThrows(fn () => $this->formQuery()->draft($this->contextOf($u0001), $draftId), AuthorizationException::class, CaseEntryGate::NOT_APPLICABLE_MESSAGE);
    }

    /**
     * 一時保存の画面は、申請できる人の 403 を状態(一時保存か)より先に見る(画面/README.md 1.1 の順 = 再申請の画面と同じ形): 見える一時保存でない案件を、申請できる人でない
     * 利用者(承認 1 の u0002)が開くと 403(詳細へ移さない)。
     */
    public function test_the_draft_form_judges_who_can_apply_before_the_state(): void
    {
        $this->createFlow('wf_only_a', 'A だけ', applicants: [$this->userCondition('u0001')]);
        $applied = $this->applyAs($this->seededUser('u0001'), 'wf_only_a');
        $u0002 = $this->contextOf($this->seededUser('u0002'));

        $this->assertThrows(fn () => $this->formQuery()->draft($u0002, $applied->id), AuthorizationException::class, CaseEntryGate::NOT_APPLICABLE_MESSAGE);
    }

    /**
     * 再申請の画面の初期値は今のレコード(明細の行は id を持つ)と今の案件名(コメントは空)。承認 1 は案件のノードの今の展開。
     */
    public function test_the_reapply_form_reads_the_current_record(): void
    {
        $this->createFlow('wf_form', 'フォームの見本');
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001, 'wf_form');
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');
        $case->refresh();

        $form = $this->formQuery()->reapply($this->contextOf($u0001), $case->id);
        $initial = $form['initial'] ?? [];
        $rows = $initial['details']['milestones'] ?? [];

        $this->assertSame(['reapply', ['id' => $case->id, 'number' => CaseNumbers::format((int) $case->case_number), 'revision' => $case->revision]], [$form['mode'] ?? null, $form['case'] ?? null]);
        $this->assertSame([$case->name, true, null, '新製品の企画'], [$initial['name'] ?? null, array_key_exists('comment', $initial), $initial['comment'] ?? null, $initial['values']['name'] ?? null]);
        $this->assertSame([true, '要件の整理'], [is_int($rows[0]['id'] ?? null), $rows[0]['values']['name'] ?? null]);
        $this->assertSame(['version' => 1, 'noVersionMessage' => null, 'departmentName' => '東京営業課', 'nodes' => [['name' => '承認1', 'targets' => [$this->person('u0002')], 'blockedReason' => null]], 'dependsOnContent' => false], $form['next'] ?? null);
        $this->assertSame([$case->base_date?->format('Y-m-d'), ['min' => null, 'max' => null]], [$form['baseDate'], $form['baseDateRange']]);
        $this->assertSame([true, $case->record_id], [is_int($case->record_id), $form['recordId'] ?? null]);
    }

    /**
     * 再申請ができない状態(承認待ち)・役割(申請のノードの処理対象者でない)は null(入口が詳細へ移してトーストを出す)。
     */
    public function test_the_reapply_form_is_null_when_reapplying_is_not_permitted(): void
    {
        $this->createFlow('wf_form', 'フォームの見本');
        $u0001 = $this->seededUser('u0001');
        $waiting = $this->applyAs($u0001, 'wf_form');
        $returned = $this->applyAs($u0001, 'wf_form');
        $this->sendBack($this->seededUser('u0002'), $returned, 'apply');
        $completed = $this->applyAs($u0001, 'wf_form');
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $completed);
        $this->operate(ApproveCase::class, $this->seededUser('u0003'), $completed);

        $this->assertNull($this->formQuery()->reapply($this->contextOf($u0001), $waiting->id));
        $this->assertNull($this->formQuery()->reapply($this->contextOf($u0001), $completed->id));
        $this->assertNull($this->formQuery()->reapply($this->contextOf($this->seededUser('u0002')), $returned->id));
    }

    /**
     * 再申請の画面は、見えない・一時保存・無い案件は 404、業務モジュールの update を許されなければ 403(再申請待ちでも)。
     */
    public function test_the_reapply_form_is_not_found_for_hidden_and_draft_cases_and_forbidden_without_update(): void
    {
        $this->createFlow('wf_form', 'フォームの見本');
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001, 'wf_form');
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');
        $draftId = app(CreateDraft::class)->execute($this->contextOf($u0001), 'wf_form', new DraftInput(null, null, [], []));

        foreach ([[$this->seededUser('u0008'), $case->id], [null, $case->id], [$u0001, $draftId], [$u0001, 0]] as [$viewer, $caseId]) {
            $this->assertThrows(fn () => $this->formQuery()->reapply($this->contextOf($viewer), $caseId), ModelNotFoundException::class);
        }

        $this->deny($this->projectFormModuleCode(), RecordModuleAction::Update);
        $this->assertThrows(fn () => $this->formQuery()->reapply($this->contextOf($u0001), $case->id), AuthorizationException::class);
    }

    /**
     * 再申請の画面は、状態の 404 を業務モジュールの update の 403 より先に見る: update を禁止された申請者が、見える自分の一時保存を開くと 404
     * (書き込みの入口の口は状態を見ないので、同じ組で 403 を投げる = 前提の確かめ)。
     */
    public function test_the_reapply_form_judges_the_state_before_the_update_permission(): void
    {
        $this->createFlow('wf_form', 'フォームの見本');
        $u0001 = $this->contextOf($this->seededUser('u0001'));
        $draftId = app(CreateDraft::class)->execute($u0001, 'wf_form', new DraftInput(null, null, [], []));
        $this->deny($this->projectFormModuleCode(), RecordModuleAction::Update);

        $this->assertThrows(fn () => app(CaseEntryGate::class)->authorizeReapply($u0001, $draftId), AuthorizationException::class);
        $this->assertThrows(fn () => $this->formQuery()->reapply($u0001, $draftId), ModelNotFoundException::class);
    }

    /**
     * 閲覧できない案件の再申請の画面の要求では、業務データの入れ物の表の SQL が出ない(主体の判定をしない口 editValues を閲覧の判定の前に呼ばない。
     * 開ける案件では出る = 記録の仕方の確かめ)。
     */
    public function test_the_records_tables_are_not_read_before_the_visibility_is_judged(): void
    {
        $this->createFlow('wf_form', 'フォームの見本');
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001, 'wf_form');
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');

        $hidden = $this->recordedSql(fn () => $this->assertThrows(fn () => $this->formQuery()->reapply($this->contextOf($this->seededUser('u0008')), $case->id), ModelNotFoundException::class));
        $visible = $this->recordedSql(fn () => $this->formQuery()->reapply($this->contextOf($u0001), $case->id));

        $this->assertSame([[], true], [preg_grep(self::RECORDS_SQL, $hidden), preg_grep(self::RECORDS_SQL, $visible) !== []]);
    }

    private function formQuery(): ApplicationFormQuery
    {
        return app(ApplicationFormQuery::class);
    }

    /**
     * 画面の人の表示(初期データの名前)。
     *
     * @return array{id: int, code: string, name: string|null, mainDepartmentId: int|null}
     */
    private function person(string $code): array
    {
        $user = User::query()->where('code', $code)->sole();
        $departmentCode = ['u0002' => 'tokyo-sales'][$code] ?? null;
        $departmentId = $departmentCode === null
            ? null
            : Department::query()->where('code', $departmentCode)->sole()->id;
        $name = ['u0002' => '中村 美咲'][$code] ?? null;

        return ['id' => $user->id, 'code' => $code, 'name' => $name, 'mainDepartmentId' => $departmentId];
    }
}
