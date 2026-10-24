<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseOperationDecider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 再申請(docs の 設計/ワークフロー/案件の操作/操作.md 3.9、業務データとの接続/申請と書き込み.md 3〜4 節・業務データとの接続/写しとつながる申請.md 5・9 節、
 * 設計/ワークフロー/案件の操作/同時実行と検査.md 7 節の「入口の 403 と `can`」):
 * レコードが変わり履歴の行が再申請で書いた版を指す、承認 1 を展開し直す、申請日時・担当組織・案件番号は変えない、案件名は入力で更新(空なら既定)。update の禁止は 403 が
 * 状態の 422 より先。持ち主の鍵で通り、未完了の間の業務データの入れ物の更新は 422 record、完了の後は通る。フロー = 申請 → 承認 1(u0002)→ 承認 2(u0003)。
 */
final class ReapplyCaseTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsWorkflowOperations;
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_reapplying_updates_the_record_and_keeps_the_application_columns(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->waitingForReapplication();
        $before = WorkflowCase::query()->findOrFail($case->id);

        $this->reapply($u0001, $case, $this->projectInput(['name' => '改訂後の企画', 'budget' => 120], comment: '直しました'));

        $case->refresh();
        $this->assertSame(
            [WorkflowCaseState::Active, 4, 'プロジェクト計画の承認 改訂後の企画', $before->case_number, $before->department_id, $before->record_id, $before->applied_at?->toIso8601String()],
            [$case->state, $case->revision, $case->name, $case->case_number, $case->department_id, $case->record_id, $case->applied_at?->toIso8601String()],
        );
        $this->assertSame('改訂後の企画', $this->fieldValue((int) $case->record_id, 'name'));
        $this->assertSame([['apply', 'done', 'reapply', []], ['approval1', 'waiting', null, [$this->seededUser('u0002')->id]], ['approval2', 'unreached', null, []]], $this->nodeStates($case));
        $history = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame([WorkflowAction::Reapply, $u0001->id, '直しました'], [$history->action, $history->user_id, $history->comment]);
        $revision = RecordRevision::query()->findOrFail($history->record_revision_id);
        $this->assertSame(
            [$case->record_id, 2, '再申請', $this->caseNode($case, 'apply')->name, $u0001->id],
            [
                $revision->record_id, $revision->revision, $revision->origin_operation, $revision->origin_node_name,
                $revision->user_id,
            ],
        );
        $milestones = (string) RecordField::query()->where('code', 'milestones')->sole()->id;
        $this->assertSame('object', DB::scalar(
            "select jsonb_typeof(snapshot->'details'->?->0->'references') from record_revisions where id = ?",
            [$milestones, $revision->id],
        ));
        $this->assertNodeInvariant($case);
    }

    /**
     * 案件名は入力で更新し、空なら既定(<フロー名> <更新した後の表示の項目の値>)。
     */
    public function test_the_name_follows_the_input_or_the_default(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->waitingForReapplication();

        $this->reapply($u0001, $case, $this->projectInput(name: '  手で付けた名前 '));
        $this->assertSame('手で付けた名前', $case->refresh()->name);

        $this->operate(PullBackCase::class, $u0001, $case);
        $this->reapply($u0001, $case, $this->projectInput(['name' => '別の企画'], name: ' '));
        $this->assertSame('プロジェクト計画の承認 別の企画', $case->refresh()->name);
    }

    /**
     * 承認 1 を案件の申請基準日で展開し直す(ルートの深さ/案件の操作/処理対象者.md 4.1): 経理部の指定で、u0014 の退職の前(2025-09-01)の申請では u0010・u0014、
     * 退職の後(操作の基準日 2026-10-13)の再申請でも申請基準日は 2025-09-01 のままなので u0010・u0014。
     */
    public function test_the_first_approval_is_expanded_again(): void
    {
        $this->createFlow('to_accounting', '経理部へ', approvals: [[new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('accounting'))]]);
        $u0001 = $this->seededUser('u0001');
        $u0010 = $this->seededUser('u0010');
        $case = $this->applyAs($u0001, 'to_accounting', date: '2025-09-01');
        $this->assertSame([$u0010->id, $this->seededUser('u0014')->id], $this->nodeStates($case)[1][3]);
        $this->sendBack($u0010, $case, 'apply');

        $this->reapply($u0001, $case);

        $this->assertSame([$u0010->id, $this->seededUser('u0014')->id], $this->nodeStates($case)[1][3]);
        $this->assertSame('2025-09-01', $case->refresh()->base_date?->toDateString());
    }

    /**
     * 業務モジュールの update を禁止すると、再申請は 403 で、状態・役割の 422(承認待ちの案件)より先に返り、何も変わらない。詳細の can は reapply が偽・
     * withdraw が真で、取止めはできる(設計/ワークフロー/案件の操作/同時実行と検査.md 7 節の「入口の 403 と `can`」の
     * 直接の呼び出しの分)。
     */
    public function test_a_denied_update_is_403_before_the_state_check(): void
    {
        $u0001 = $this->seededUser('u0001');
        $waiting = $this->applyAs($u0001);
        $case = $this->waitingForReapplication();
        $this->deny('project', RecordModuleAction::Update);
        $counts = $this->workflowCounts();

        foreach ([$waiting, $case] as $target) {
            $this->assertThrows(fn () => $this->reapply($u0001, $target), AuthorizationException::class, 'この業務モジュールの操作は許可されていません');
        }

        $this->assertSame([$counts, 0, 3], [$this->workflowCounts(), $this->revisionOf($waiting), $this->revisionOf($case)]);
        $requester = new Requester($u0001->id, null);
        $situation = app(CaseOperationDecider::class)->situationsOf($requester, $this->operationDay(), [$case->refresh()])[$case->id];
        $can = app(CaseOperationDecider::class)->detailCanOf($requester, $this->operationDay(), $situation);
        $this->assertSame([false, true], [$can['reapply'], $can['withdraw']]);
        $this->operate(WithdrawCase::class, $u0001, $case);
        $this->assertSame(WorkflowCaseState::Completed, $case->refresh()->state);
    }

    /**
     * 再申請は業務モジュールの update の判定を 1 回だけ行い、申請できる人は判定しない(表が読まない役割を評価しない)。
     */
    public function test_reapplying_judges_the_update_once(): void
    {
        $case = $this->waitingForReapplication();

        $sql = $this->recordedSql(fn () => $this->reapply($this->seededUser('u0001'), $case));

        $this->assertSame([1, 0], [count(preg_grep(self::UPDATE_JUDGEMENT_SQL, $sql) ?: []), count(preg_grep(self::APPLY_NODE_SQL, $sql) ?: [])]);
    }

    /**
     * 未完了の間、業務データの入れ物の更新(鍵なし)は 422 record で、再申請(持ち主の鍵)は通る。完了の後は鍵なしの更新が通る。
     */
    public function test_the_record_is_held_while_the_case_is_active(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->waitingForReapplication();
        $update = fn () => app(UpdateRecord::class)->execute($this->contextOf($u0001), 'project', (int) $case->record_id, new RecordInput(['name' => '画面から']), Record::query()->findOrFail($case->record_id)->version());

        $this->assertSame(['record' => '申請中のため変更できません'], $this->validationErrors($update));

        $this->reapply($u0001, $case, $this->projectInput(['name' => '再申請で']));
        $this->assertSame('再申請で', $this->fieldValue((int) $case->record_id, 'name'));
        $this->operate(PullBackCase::class, $u0001, $case);
        $this->operate(WithdrawCase::class, $u0001, $case);

        $update();

        $this->assertSame('画面から', $this->fieldValue((int) $case->record_id, 'name'));
    }

    /**
     * 業務データの入れ物の検証の失敗はそのキーのまま伝わり、レコードも案件も変わらない(外側ごと巻き戻る = 設計/ワークフロー/データモデル/直列化と検査.md 3.2 の未確認の項)。
     */
    public function test_a_record_validation_failure_changes_nothing(): void
    {
        $case = $this->waitingForReapplication();
        $counts = $this->workflowCounts();
        $values = Record::query()->findOrFail($case->record_id)->field_values;

        $errors = $this->validationErrors(fn () => $this->reapply($this->seededUser('u0001'), $case, new ApplicationInput(null, null, new RecordInput(['name' => null]))));

        $this->assertSame(['values.name'], array_keys($errors));
        $this->assertSame([$counts, 3, $values], [$this->workflowCounts(), $this->revisionOf($case), Record::query()->findOrFail($case->record_id)->field_values]);
        $this->assertSame('reapply', $this->nodeStates($case)[0][1]);
    }

    /**
     * 再申請待ちでない案件・申請者でない人は 422 case(閲覧できる処理者)。
     */
    public function test_only_the_applicant_waiting_for_reapplication_can_reapply(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);

        foreach (['u0001', 'u0002'] as $code) {
            $this->assertSame(['case' => 'この申請は今この操作をできません'], $this->validationErrors(fn () => $this->reapply($this->seededUser($code), $case)));
        }

        $this->assertSame(1, $this->revisionOf($case));
    }

    /**
     * u0001 が申請し、u0002 が承認 → 引戻し → 申請へ差戻しした案件(改訂番号 3)。
     */
    private function waitingForReapplication(): WorkflowCase
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);
        $this->operate(PullBackCase::class, $this->seededUser('u0002'), $case);
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');
        $this->assertSame(['apply', 'reapply'], array_slice($this->nodeStates($case)[0], 0, 2));

        return $case;
    }

    private function fieldValue(int $recordId, string $code): mixed
    {
        $fieldId = RecordField::query()->where('code', $code)->where('record_module_id', Record::query()->findOrFail($recordId)->record_module_id)->sole()->id;

        return Record::query()->findOrFail($recordId)->field_values[$fieldId] ?? null;
    }
}
