<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\SendBackCase;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Master\UserTerm;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\BuildsRouteVersions;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 配置の親へ差戻したときの子の人(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 4.5 の書き戻す文。区切り 3-3 のレビューの #1・#2):
 * if(差戻しで到達させる子(縦配置は全部、横配置は子 1)&& 差戻しの直前に処理済み){その子が指していた履歴の行の処理者}else{今の指定の同じ番号の人}。
 * 差戻しの時点で確かめる人(申請基準日に有効)= 差戻しで到達させる人と、横配置の子 2〜n の今の指定。期待はこの文から書いた。3a では処理済みの子の処理者は
 * 今の指定の同じ番号の人と同じなので、指している行の処理者が今の指定と違う場面は、履歴の行の user_id を直して作る(3b の代理の処理 = 処理者が代理元を写す)。
 * ルート = {@see BuildsRouteVersions::placementRoute()}(chief(u0002)が指定、final(u0010)から配置の親へ差戻せる)。申請者 u0001。
 */
final class CaseSendBackToPlacementTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesOnRoutes;

    /**
     * 1 回目に子 3(u0009)が承認 → 申請へ差戻し → 2 回目は指定を 3 人のまま 3 番目を u0003 に替えて子 1・子 2 で可決(子 3 は省略)→ u0009 を申請基準日に
     * 無効にしておく → 後のノードから親へ差戻すと 422 にならず、子 1・子 2 = 2 回目の処理者、子 3 = 今の指定の 3 番目(u0003)。前の回の履歴は見ない。
     */
    public function test_a_child_skipped_in_this_round_gets_the_current_assignee_not_the_processor_of_an_earlier_round(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0004', 'u0005', 'u0009']]);
        $this->approve($case, 'family.3', 'u0009');
        UserTerm::query()->where('user_id', $this->seededUser('u0009')->id)->update(['end_date' => '2026-10-01']);
        $this->sendBackOn($case, 'family.1', 'u0004', 'apply');
        $this->reapply($this->seededUser('u0001'), $case);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0004', 'u0005', 'u0003']]);
        $this->approve($case, 'family.1', 'u0004');
        $this->approve($case, 'family.2', 'u0005');
        $this->assertSame(['passed', 'done', 'done', 'skipped', 'waiting[u0010]'], [...$this->familyShape($case, 'family'), $this->shape($case)['final']]);

        $result = $this->sendBackOn($case, 'final', 'u0010', 'family');

        $this->assertSame(['running', 'waiting[u0004]', 'waiting[u0005]', 'waiting[u0003]', 'unreached'], [...$this->familyShape($case, 'family'), $this->shape($case)['final']]);
        $this->assertResult($case, $result, WorkflowAction::SendBack, 'u0010', ['family.1' => ['u0004'], 'family.2' => ['u0005'], 'family.3' => ['u0003']], null);
    }

    /**
     * 縦配置の子は、差戻しの直前に指していた履歴の行の処理者で到達する(今の指定に無い人でも。子 1 の行の処理者を u0003 にした = 代理の処理を写す)。
     * 省略だった子 3 は今の指定の 3 番目。
     */
    public function test_a_parallel_child_returns_to_the_processor_of_the_row_it_points_to(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0004', 'u0005', 'u0009']]);
        $this->approve($case, 'family.1', 'u0004');
        $this->approve($case, 'family.2', 'u0005');
        $this->rewriteProcessorOf($case, 'family.1', 'u0003');

        $this->sendBackOn($case, 'final', 'u0010', 'family');

        $this->assertSame(['running', 'waiting[u0003]', 'waiting[u0005]', 'waiting[u0009]'], $this->familyShape($case, 'family'));
    }

    /**
     * 横配置は子 1 だけを差戻しで到達させ、子 1 は指していた行の処理者(今の指定に無い u0003 にした)で到達する。子 2 が指していた行の処理者(申請基準日に
     * 無効な u0014 にした)は確かめず、422 にならない。子 1 の承認の後に、子 2 は今の指定の 2 番目(u0005)で到達する。
     */
    public function test_a_serial_parent_checks_and_reaches_only_the_first_child_with_its_previous_processor(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Serial));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0004', 'u0005']]);
        $this->approve($case, 'family.1', 'u0004');
        $this->approve($case, 'family.2', 'u0005');
        $this->rewriteProcessorOf($case, 'family.1', 'u0003');
        $this->rewriteProcessorOf($case, 'family.2', 'u0014');

        $this->sendBackOn($case, 'final', 'u0010', 'family');

        $this->assertSame(['running', 'waiting[u0003]', 'unreached', 'unreached'], [...$this->familyShape($case, 'family'), $this->shape($case)['final']]);

        $this->approve($case, 'family.1', 'u0003');

        $this->assertSame(['running', 'done', 'waiting[u0005]'], $this->familyShape($case, 'family'));
    }

    /**
     * 横配置の親へ差戻すとき、子 2 以降の今の指定の人も差戻しの時点で確かめる(差戻しの時点で確かめる人 = 差戻しで到達させる人と、横配置の子 2〜n の今の指定)。
     * 今の指定の 2 番目(u0005)が申請基準日に無効なら 422 case で、件数・状態・改訂番号は差戻しの前のまま。
     */
    public function test_a_serial_parent_rejects_a_send_back_when_a_later_current_assignee_is_not_effective(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Serial));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0004', 'u0005']]);
        $this->approve($case, 'family.1', 'u0004');
        $this->approve($case, 'family.2', 'u0005');
        UserTerm::query()->where('user_id', $this->seededUser('u0005')->id)->update(['end_date' => '2026-10-01']);
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
        $ids = $this->labelIds($case);

        $errors = $this->validationErrors(fn () => app(SendBackCase::class)->execute($this->contextOf($this->seededUser('u0010')), $case->id, $this->revisionOf($case), $ids['final'], $ids['family'], null));

        $this->assertSame(['case' => '配置の処理対象者がいないため、この操作はできません'], $errors);
        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * ノードが指している履歴の行の処理者を替える(3b の代理の処理 = 処理者が今の指定と違う行を写す)。
     */
    private function rewriteProcessorOf(WorkflowCase $case, string $label, string $userCode): void
    {
        $historyId = WorkflowCaseNode::query()->findOrFail($this->labelIds($case)[$label])->workflow_case_history_id;
        WorkflowCaseHistory::query()->whereKey($historyId)->update(['user_id' => $this->seededUser($userCode)->id]);
    }
}
