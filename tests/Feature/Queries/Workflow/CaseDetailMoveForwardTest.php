<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\CaseDetailQuery;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\TransactionalTestCase;

/**
 * 申請のノード(再申請待ち)を進めた履歴の行(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節、画面/案件の画面.md の 4 節):
 * 進むはレコードを変えないので、詳細の履歴の行は種別の札とコメントだけで差分を持たない。「提出の後に更新された」の基準は最後の申請・再申請の行のまま
 * (進むは提出でない)。R1 を申請者 u0001 が申請 → 課長 u0002 が差戻し → 再申請 → 差戻し → 管理者 u0004 が申請のノードを進める。今日 = 2026-10-13。
 */
final class CaseDetailMoveForwardTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;

    public function test_moving_the_application_forward_shows_no_diff_and_keeps_the_submission(): void
    {
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute([]);
        $this->sendBackOn($case, 'section_chief', 'u0002', 'apply');
        $this->reapply($this->seededUser('u0001'), $case);
        $this->sendBackOn($case, 'section_chief', 'u0002', 'apply');
        $this->moveOn($case, 'apply', 'forward', comment: '管理者が進めた');
        $rowsOf = fn (WorkflowAction $action) => WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)
            ->where('action', $action)->toBase();
        $rowsOf(WorkflowAction::Reapply)->update(['created_at' => '2026-10-13 01:00:00+00']);
        $rowsOf(WorkflowAction::MoveForward)->update(['created_at' => '2026-10-13 03:00:00+00']);
        Record::query()->whereKey($case->record_id)->toBase()->update(['updated_at' => '2026-10-13 02:00:00+00']);

        $detail = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0001')), $case->id);

        $moved = $detail['histories'][count($detail['histories']) - 1];
        $this->assertSame(
            ['move_forward', '管理者が進めた', null, null],
            [$moved['action'], $moved['comment'], $moved['diff'], $moved['diffBaseAt']],
        );
        $this->assertTrue($detail['content']['updatedAfterSubmission']);
    }
}
