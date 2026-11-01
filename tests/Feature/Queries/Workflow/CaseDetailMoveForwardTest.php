<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\CaseShowProps;
use App\Queries\Workflow\CaseShowSelection;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\TransactionalTestCase;

/**
 * 申請のノード(再申請待ち)を進めた履歴の行(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節、画面/案件の画面.md の 4 節):
 * 進むはレコードを変えないので、詳細の履歴の行は種別の札とコメントだけ。版も書かないので「申請の後の変更」の 1 行は出ない
 * (設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 2 節)。R1 を申請者 u0001 が申請 → 課長 u0002 が差戻し → 再申請 → 差戻し →
 * 管理者 u0004 が申請のノードを進める。今日 = 2026-10-13。
 */
final class CaseDetailMoveForwardTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;

    public function test_moving_the_application_forward_writes_no_revision_and_no_change_after_submission(): void
    {
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute([]);
        $this->sendBackOn($case, 'section_chief', 'u0002', 'apply');
        $this->reapply($this->seededUser('u0001'), $case);
        $this->sendBackOn($case, 'section_chief', 'u0002', 'apply');
        $this->moveOn($case, 'apply', 'forward', comment: '管理者が進めた');
        $moved = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)
            ->where('action', WorkflowAction::MoveForward)->sole();

        $detail = app(CaseShowProps::class)
            ->of($this->contextOf($this->seededUser('u0001')), $case->id, new CaseShowSelection);

        $row = $detail['histories'][count($detail['histories']) - 1];
        $this->assertSame(['move_forward', '管理者が進めた'], [$row['action'], $row['comment']]);
        $this->assertNull($moved->record_revision_id);
        $this->assertFalse($detail['content']['updatedAfterSubmission']);
    }
}
