<?php

namespace Tests\Feature\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの移動の終了(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節・15.2 節、同時実行と検査.md の 14.2 節・14.3 節の (1)):
 * 操作するノードを処理済み(終了)にし、案件を完了(承認終了)にして完了の片付けをする(保留中の子も省く)。申請のノード(再申請待ち)でも終了できる。
 * 申請者に処理結果(承認終了)が届き、関わる人の一覧で完了の側へ移る。管理者 = u0004。
 */
final class MoveCaseNodeFinishTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;
    use ReadsCaseNotices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 申請 → a1(u0002)→ a2(u0003): a1 で終了すると a1 = 処理済み(終了)、a2 = 到達していないのまま、案件 = 完了(承認終了)。申請者に処理結果
     * (承認終了)が届き、u0002 の未処理から消え、管理者の処理済(完了)に出る。
     */
    public function test_finishing_completes_the_case_as_approved_early(): void
    {
        $case = $this->lineCase();
        $lastId = $this->lastNoticeId();

        $this->moveOn($case, 'a1', 'finish');

        $this->assertSame(['a1' => 'done', 'a2' => 'unreached'], $this->pick($case, 'a1', 'a2'));
        $case->refresh();
        $this->assertSame(['completed', 'approved_early'], [$case->state->value, $case->completion_reason?->value]);
        $this->assertSame(['move_finish', self::MANAGER, null], array_slice($this->latestRow($case), 0, 3));
        $params = ['completion_reason' => 'approved_early'];
        $result = $this->caseNotice(WorkflowNoticeType::Result, 'u0001', self::MANAGER, $case, $params);
        $this->assertSame([$result], $this->noticesSince($lastId));
        $this->assertSame(
            ['u0001' => ['processed'], 'u0002' => [], self::MANAGER => ['processed']],
            $this->appearances($case, 'u0001', 'u0002', self::MANAGER),
        );
    }

    /**
     * 合議の子 2 が保留中のまま子 1 で終了すると、完了の片付けで保留中の子 2・承認待ちの子 3・展開中の親も省く(後の final は到達していないのまま)。
     */
    public function test_finishing_skips_a_held_council_child(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009', 'u0011']]);
        $this->holdOn($case, 'family.2', 'u0009');

        $this->moveOn($case, 'family.1', 'finish');

        $shape = [...$this->familyShape($case, 'family'), $this->shape($case)['final']];
        $this->assertSame(['skipped', 'done', 'skipped', 'skipped', 'unreached'], $shape);
        $this->assertSame('approved_early', $case->refresh()->completion_reason?->value);
    }

    /**
     * 申請へ差戻した再申請待ちの申請のノードでも終了でき、保留中のノード(a1 を u0002 が保留)でも終了できる(保留は消える)。
     */
    public function test_finishing_at_the_application_or_a_held_node(): void
    {
        $atApplication = $this->lineCase();
        $atHeld = $this->lineCase();
        $this->sendBackOn($atApplication, 'a1', 'u0002', 'apply');
        $this->holdOn($atHeld, 'a1', 'u0002');

        $this->moveOn($atApplication, 'apply', 'finish');
        $this->moveOn($atHeld, 'a1', 'finish');

        $this->assertSame(['apply' => 'done', 'a1' => 'unreached'], $this->pick($atApplication, 'apply', 'a1'));
        $this->assertSame(['a1' => 'done', 'a2' => 'unreached'], $this->pick($atHeld, 'a1', 'a2'));
        $reasonOf = fn (WorkflowCase $case) => $case->refresh()->completion_reason?->value;
        $reasons = array_map($reasonOf, [$atApplication, $atHeld]);
        $this->assertSame(['approved_early', 'approved_early'], $reasons);
    }

    private bool $hasLineRoute = false;

    /**
     * 申請 → a1(u0002)→ a2(u0003)の案件。
     */
    private function lineCase(): WorkflowCase
    {
        if (! $this->hasLineRoute) {
            $nodes = [
                $this->applyNode(),
                $this->approveNode('a1', 'u0002', ['apply']),
                $this->approveNode('a2', 'u0003', ['apply', 'a1']),
            ];
            $this->addVersion([$nodes, $this->chain(['apply', 'a1'], ['a1', 'a2'])]);
            $this->hasLineRoute = true;
        }

        return $this->applyOnRoute([])[0];
    }
}
