<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CaseOperationErrors;
use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Workflow\WorkflowConfirmationProgress;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseConfirmations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesHolds;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 確認(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.4 節、README.md の 1.5 節、同時実行と検査.md の 12 節と 14.2 節)。
 * ルートの見本 R1(予算 100)の課長に確認する人を付けた版で、課長(u0002)が合議 u0005・u0009・u0011 と責任者 u0007 を指定して承認すると確認が開く。
 * 確認対象者 = 経理部の u0010(フローの参照者なので閲覧できる。確認対象者だけの人の閲覧の述語はまだ無い)。
 */
final class ConfirmCaseTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesHolds;
    use ReadsCaseNotices;
    use RegistersProxies;

    /** 合議の 3 人と責任者 */
    private const DESIGNATION = ['council' => ['u0005', 'u0009', 'u0011'], 'owner' => ['u0007']];

    /** 確認できる確認が無いときの失敗 */
    private const NONE = ['case' => CaseOperationErrors::NO_CONFIRMATION];

    /**
     * 確認対象者は申請基準日で展開する(基準日 2026-10-13 に退職済みの u0014・入社前の u0015 は入らない。操作の今日 2026-11-05 には u0015 が有効)。
     */
    public function test_processing_a_confirming_node_opens_it_with_the_targets_on_the_base_date(): void
    {
        $this->addConfirmingVersion('u0010', 'u0014', 'u0015');
        $today = '2026-11-05';
        $input = $this->projectInput(['budget' => 100]);
        $applicant = $this->contextOf($this->seededUser('u0001'), date: $today);
        $applied = app(ApplyCase::class)->execute($applicant, self::FLOW_CODE, $input, '2026-10-13');
        $case = WorkflowCase::query()->findOrFail($applied->caseId);
        $chiefId = $this->labelIds($case)['section_chief'];
        $chief = $this->contextOf($this->seededUser('u0002'), date: $today);

        $result = app(ApproveCase::class)
            ->execute($chief, $case->id, $this->revisionOf($case), $chiefId, null, $this->designationIds($case));

        $target = $this->seededUser('u0010')->id;
        $this->assertSame(['u0010'], $this->confirmTargetCodes($chiefId));
        $this->assertSame([$chiefId => [$target]], $result->openedConfirmations);
        $this->assertSame([$chiefId => [$target => WorkflowConfirmationProgress::Unconfirmed]], $this->states($case));
    }

    /**
     * 展開が 0 人なら確認は無く、操作は拒まない。
     */
    public function test_no_confirmation_when_no_one_is_expanded(): void
    {
        $this->addConfirmingVersion('u0014');
        [$case] = $this->applyOnRoute(['budget' => 100]);

        $result = $this->approveChief($case);

        $this->assertSame([], $result->openedConfirmations);
        $this->assertSame([], $this->confirmTargetCodes($this->labelIds($case)['section_chief']));
        $this->assertSame(self::NONE, $this->validationErrors(fn () => $this->confirmAs($case, 'u0010')));
    }

    /**
     * 何度でも確認でき、行が増える。確認で案件の行・ノード・処理対象者・改訂番号が変わらず、通知の行も増えない。確認は案件の行を FOR SHARE で取る。
     */
    public function test_confirming_adds_a_row_each_time_and_changes_nothing_else(): void
    {
        $case = $this->confirmingCase();
        $chiefId = $this->labelIds($case)['section_chief'];
        $snapshot = fn () => [$this->shape($case), $this->revisionOf($case), $this->countsWithoutConfirmations()];
        $before = $snapshot();

        $sql = $this->recordedSql(fn () => $this->assertSame([$chiefId], $this->confirmAs($case, 'u0010')));
        $this->assertSame([$chiefId], $this->confirmAs($case, 'u0010'));

        $this->assertSame($before, $snapshot());
        $historyId = WorkflowCaseNode::query()->findOrFail($chiefId)->workflow_case_history_id;
        $rows = WorkflowCaseConfirmation::query()->where('workflow_case_id', $case->id)->get();
        $pairs = $rows->map(fn (WorkflowCaseConfirmation $row) => [
            $row->workflow_case_node_id, $row->workflow_case_history_id,
        ]);
        $this->assertSame([[$chiefId, $historyId], [$chiefId, $historyId]], $pairs->all());
        $this->assertSame(WorkflowConfirmationProgress::Confirmed, $this->stateOf($case));
        $shared = fn (string $statement) => str_contains($statement, 'from "workflow_cases"')
            && str_contains($statement, 'for share');
        $this->assertNotSame([], array_filter($sql, $shared));
    }

    /**
     * 差戻しで閉じ(確認は 422)、新しい処理の履歴の行で再び処理済みになると未確認に戻る(前の確認の行は残り、確認対象者の行は書き直さない)。
     */
    public function test_a_send_back_closes_it_and_new_processing_reopens_it_unconfirmed(): void
    {
        $case = $this->confirmingCase();
        $chiefId = $this->labelIds($case)['section_chief'];
        $this->confirmAs($case, 'u0010');

        $this->sendBackOn($case, 'council.1', 'u0005', 'section_chief');

        $this->assertSame(WorkflowConfirmationProgress::Waiting, $this->stateOf($case));
        $this->assertSame(self::NONE, $this->validationErrors(fn () => $this->confirmAs($case, 'u0010')));

        $result = $this->approveChief($case);

        $this->assertSame([$chiefId => [$this->seededUser('u0010')->id]], $result->openedConfirmations);
        $this->assertSame(WorkflowConfirmationProgress::Unconfirmed, $this->stateOf($case));
        $this->assertSame(1, $this->confirmationCount($case));
        $this->assertSame(['u0010'], $this->confirmTargetCodes($chiefId));
    }

    /**
     * 差戻しを引戻すと前の開きに戻り、前に確認した人は確認済のまま。確認依頼は増えない(写しから戻したノードは開いたノードに入らない)。
     */
    public function test_pulling_back_a_send_back_returns_to_the_previous_opening(): void
    {
        $case = $this->confirmingCase();
        $this->confirmAs($case, 'u0010');
        $this->sendBackOn($case, 'council.1', 'u0005', 'section_chief');
        $lastId = $this->lastNoticeId();

        $result = $this->pullBackOn($case, 'council.1', 'u0005');

        $this->assertSame([], $result->openedConfirmations);
        $this->assertSame(WorkflowConfirmationProgress::Confirmed, $this->stateOf($case));
        $isRequest = fn (array $line) => $line[0] === WorkflowNoticeType::ConfirmRequest->value;
        $this->assertSame([], array_filter($this->noticesSince($lastId), $isRequest));
    }

    /**
     * 案件の完了の後は確認できない(処理済みで確認しないまま完了 = 未確認)。差戻しで戻ったまま完了したノードは「—」、戻って保留されたノードは承認待ち。
     */
    public function test_after_completion_it_cannot_be_confirmed_and_the_states_follow_the_formula(): void
    {
        $case = $this->confirmingCase();
        $this->operateOn(ApproveCaseEarly::class, $case, 'council.1', 'u0005');

        $this->assertSame(self::NONE, $this->validationErrors(fn () => $this->confirmAs($case, 'u0010')));
        $this->assertSame(WorkflowConfirmationProgress::Unconfirmed, $this->stateOf($case));

        $returned = $this->confirmingCase();
        $this->sendBackOn($returned, 'council.1', 'u0005', 'section_chief');
        $this->holdOn($returned, 'section_chief', 'u0002');
        $this->assertSame(WorkflowConfirmationProgress::Waiting, $this->stateOf($returned));
        $this->releaseOn($returned, 'section_chief', 'u0002');
        $this->sendBackOn($returned, 'section_chief', 'u0002', 'apply');
        $this->operate(WithdrawCase::class, $this->seededUser('u0001'), $returned);

        $this->assertSame(WorkflowConfirmationProgress::NotProcessed, $this->stateOf($returned));
    }

    /**
     * 確認対象者でない閲覧者は 422 case、閲覧できない人は 404。代理先は代理元の確認をできない(確認は本人だけ)。
     */
    public function test_only_the_target_itself_can_confirm(): void
    {
        $case = $this->confirmingCase();
        $this->addProxy($this->seededUser('u0010'), $this->seededUser('u0002'));

        $this->assertSame(self::NONE, $this->validationErrors(fn () => $this->confirmAs($case, 'u0002')));
        $this->assertThrows(fn () => $this->confirmAs($case, 'u0006'), ModelNotFoundException::class);
        $this->assertSame(0, $this->confirmationCount($case));
    }

    /**
     * 確認と差戻しの順(12 節): 先に差戻しが確定すれば確認は 422 case。先に確認が確定すれば確認の行が残り、差戻しの後に再び処理済みになると未確認。
     */
    public function test_confirm_and_send_back_in_either_order(): void
    {
        $sentBackFirst = $this->confirmingCase();
        $this->sendBackOn($sentBackFirst, 'council.1', 'u0005', 'section_chief');
        $this->assertSame(self::NONE, $this->validationErrors(fn () => $this->confirmAs($sentBackFirst, 'u0010')));

        $confirmedFirst = $this->confirmingCase();
        $this->confirmAs($confirmedFirst, 'u0010');
        $this->sendBackOn($confirmedFirst, 'council.1', 'u0005', 'section_chief');
        $this->approveChief($confirmedFirst);

        $this->assertSame(1, $this->confirmationCount($confirmedFirst));
        $this->assertSame(WorkflowConfirmationProgress::Unconfirmed, $this->stateOf($confirmedFirst));
    }

    /**
     * 確認する人を課長に付けた R1 を、テストのフロー(無ければ作る)の最後の版に足す。
     */
    private function addConfirmingVersion(string ...$confirmers): void
    {
        if ($this->flowAbsent()) {
            $this->createFlow();
        }

        $this->addVersion($this->r1($this->confirmGroup(...$confirmers)));
    }

    private function flowAbsent(): bool
    {
        return ! WorkflowFlow::query()->where('code', self::FLOW_CODE)->exists();
    }

    /**
     * 確認が開いた案件(確認対象者 u0010)。2 件目からは同じ版を使う。
     */
    private function confirmingCase(): WorkflowCase
    {
        if ($this->flowAbsent()) {
            $this->addConfirmingVersion('u0010');
        }

        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approveChief($case);

        return $case;
    }

    private function approveChief(WorkflowCase $case): CaseOperationResult
    {
        return $this->approve($case, 'section_chief', 'u0002', self::DESIGNATION);
    }

    /**
     * @return array<int, list<int>>
     */
    private function designationIds(WorkflowCase $case): array
    {
        $ids = $this->labelIds($case);
        $council = array_map(fn (string $code) => $this->seededUser($code)->id, self::DESIGNATION['council']);

        return [$ids['council'] => $council, $ids['owner'] => [$this->seededUser('u0007')->id]];
    }

    /**
     * @return array<int, array<int, WorkflowConfirmationProgress>>
     */
    private function states(WorkflowCase $case): array
    {
        return app(CaseConfirmations::class)->statesOf(WorkflowCase::query()->findOrFail($case->id));
    }

    /**
     * 課長の u0010 の確認の状態。
     */
    private function stateOf(WorkflowCase $case): WorkflowConfirmationProgress
    {
        return $this->states($case)[$this->labelIds($case)['section_chief']][$this->seededUser('u0010')->id];
    }

    private function confirmationCount(WorkflowCase $case): int
    {
        return WorkflowCaseConfirmation::query()->where('workflow_case_id', $case->id)->count();
    }

    /**
     * @return array<string, int> 確認の行を除く行の数(通知を含む)
     */
    private function countsWithoutConfirmations(): array
    {
        $counts = $this->workflowCounts();
        unset($counts['confirmations']);

        return $counts;
    }
}
