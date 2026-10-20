<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CasePlanRequest;
use App\Actions\Workflow\CaseRoutePlanner;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use Tests\Support\OperatesHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 並列 × 保留・振替(docs の 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.3 節の (1)〜(4))と、業務処理で作った保留の行を
 * 計画と読み取りが現在のノードとして読むこと(設計/ワークフロー/案件の運用/案件の操作/README.md の 1.2 節の「並列」・1.3 節)。
 * R1 の合議 = 課長(u0002)が X・Y・Z(u0005・u0009・u0011。人数 2 で可決)と責任者 u0007 を指定、R2 = 枝 A(a1 u0002 → a2 u0003)と枝 B(b1 u0004)。
 */
final class HoldTransferOnRoutesTest extends TransactionalTestCase
{
    use OperatesHolds;

    /**
     * (1) 枝 A の a1 を保留 → 枝 B を承認しても合流は通らない → 保留解除 → 枝 A を承認 → 合流が通過して final が承認待ち。
     */
    public function test_a_held_sync_branch_keeps_the_join_waiting_until_it_is_released_and_approved(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $this->holdOn($case, 'a1', 'u0002');

        $this->approve($case, 'b1', 'u0004');
        $waiting = $this->pick($case, 'a1', 'b1', 'sync_end', 'final');
        $this->releaseOn($case, 'a1', 'u0002');
        $this->approve($case, 'a1', 'u0002');
        $this->approve($case, 'a2', 'u0003');

        $this->assertSame(
            ['a1' => 'hold[u0002]', 'b1' => 'done', 'sync_end' => 'unreached', 'final' => 'unreached'],
            $waiting,
        );
        $after = $this->pick($case, 'sync_end', 'final');
        $this->assertSame(['sync_end' => 'passed', 'final' => 'waiting[u0010]'], $after);
    }

    /**
     * (2) 合議の子 3(Z)を保留 → X・Y の承認で可決 → 保留中の子は省略になり、Z の未処理から消える。可決の承認の計画は保留中の子を到達したノードに
     * 入れず、省く動きを処理済みの後・到達の前に書く。
     */
    public function test_passing_a_council_skips_a_held_child(): void
    {
        $case = $this->councilCase();
        $this->holdOn($case, 'council.3', 'u0011');
        $this->approve($case, 'council.1', 'u0005');
        $ids = $this->labelIds($case);
        $plan = app(CaseRoutePlanner::class)->plan(new CasePlanRequest(
            WorkflowCase::query()->findOrFail($case->id),
            $ids['council.2'],
            WorkflowAction::Approve,
            $this->seededUser('u0009')->id,
            null,
        ));

        $this->approve($case, 'council.2', 'u0009');

        $this->assertSame([$ids['owner'] => [$this->seededUser('u0007')->id]], $plan->reachedNodes());
        $this->assertSame([$ids['council.2'], $ids['council'], $ids['council.3'], $ids['owner']], $plan->writeOrder());
        $this->assertSame(WorkflowNodeState::Hold, $plan->before[$ids['council.3']]['state']);
        $this->assertSame(
            ['council' => 'passed', 'council.3' => 'skipped', 'owner' => 'waiting[u0007]'],
            $this->pick($case, 'council', 'council.3', 'owner'),
        );
        $this->assertSame(['u0011' => ['processed']], $this->appearances($case, 'u0011'));
    }

    /**
     * (3) 合議の子 1(X)を、ほかの子の人(Y)へ振替 → 422。別の人(u0008)へ振替 → ほかの子は変わらない。合議の進み具合は保留中の子を
     * 待ちの数に数える。
     */
    public function test_transferring_a_council_child_and_counting_a_held_child(): void
    {
        $case = $this->councilCase();
        $this->holdOn($case, 'council.2', 'u0009');

        $sibling = $this->seededUser('u0009')->id;
        $error = $this->attemptOf(fn () => $this->transferOn($case, 'council.1', 'u0005', $sibling));
        $this->transferOn($case, 'council.1', 'u0005', $this->seededUser('u0008')->id);

        $this->assertSame(['to_user_id' => 'このステップのほかの承認者と同じユーザーは指定できません'], $error);
        $this->assertSame(
            ['running', 'waiting[u0008]', 'hold[u0009]', 'waiting[u0011]'],
            array_values($this->pick($case, 'council', 'council.1', 'council.2', 'council.3')),
        );
        $detail = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0008')), $case->id);
        $council = $detail['councils'][0];
        $this->assertSame(
            [0, 2, 3, false],
            [$council['approved'], $council['required'], $council['pending'], $council['passed']],
        );
    }

    /**
     * (4) 保留中の子があるまま承認終了 → 完了の片付けで保留中の子も省略。
     */
    public function test_approving_early_with_a_held_child_skips_it(): void
    {
        $case = $this->councilCase();
        $this->holdOn($case, 'council.3', 'u0011');

        $this->operateOn(ApproveCaseEarly::class, $case, 'council.1', 'u0005');

        $this->assertSame(
            ['council' => 'skipped', 'council.1' => 'done', 'council.2' => 'skipped', 'council.3' => 'skipped'],
            $this->pick($case, 'council', 'council.1', 'council.2', 'council.3'),
        );
        $this->assertSame('completed', WorkflowCase::query()->findOrFail($case->id)->state->value);
    }

    /**
     * R1(予算 100 = 部長を省く)を申請し、課長が合議 X・Y・Z と責任者 u0007 を指定して承認した案件。
     */
    private function councilCase(): WorkflowCase
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $assignments = ['council' => ['u0005', 'u0009', 'u0011'], 'owner' => ['u0007']];
        $this->approve($case, 'section_chief', 'u0002', $assignments);

        return $case;
    }
}
