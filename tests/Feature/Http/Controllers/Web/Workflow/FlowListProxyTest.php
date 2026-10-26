<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * フローの一覧(申請する)の代理(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節の最後の箇条、案件の操作/README.md の 3.1 節): 自分か、
 * 申請の代理元のだれかが申請できるフローを出す。入口の 403 と同じ判定なので、出たフローの申請の画面は開け、出ないフローは 403。
 * 代理元 A = u0009 だけが申請できるフロー・代理先 B = u0002 だけが申請できるフロー・どちらも申請できないフロー(u0010 だけ)の 3 つを作る。
 */
final class FlowListProxyTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const BY_A = 'wf_by_a';

    private const BY_B = 'wf_by_b';

    private const BY_NOBODY = 'wf_by_nobody';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $approvals = [[$this->userCondition('u0003')]];
        $this->createFlow(self::BY_A, 'A のフロー', $approvals, [$this->userCondition('u0009')]);
        $this->createFlow(self::BY_B, 'B のフロー', $approvals, [$this->userCondition('u0002')]);
        $this->createFlow(self::BY_NOBODY, '誰でもないフロー', $approvals, [$this->userCondition('u0010')]);
    }

    /**
     * 自分だけが申請できる(代理なし)/ 代理元だけが申請できる(出る)/ どちらも申請できない(出ない)。出たフローの申請の画面は 200、出ないフローは 403。
     */
    public function test_the_list_has_the_flows_the_user_or_a_principal_can_apply(): void
    {
        [$a, $b] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $withoutProxy = $this->listedFor($b);
        $this->addProxy($a, $b, WorkflowProxyKind::Apply);
        $listed = $this->listedFor($b);

        $this->assertSame([self::BY_B], $withoutProxy);
        $this->assertSame([self::BY_A, self::BY_B], $listed);

        foreach ($listed as $code) {
            $this->get("/workflow/flows/{$code}/apply")->assertOk();
        }

        $this->get('/workflow/flows/'.self::BY_NOBODY.'/apply')->assertForbidden();
    }

    /**
     * 期間の外・別のフローにだけ効く・承認の代理の代理元は、代理元が申請できるフローでも出さない(入口も 403)。
     */
    public function test_proxies_that_do_not_apply_do_not_add_flows(): void
    {
        [$a, $b] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, end: '2026-10-13');
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowIdOf(self::BY_B));
        $this->addProxy($a, $b, WorkflowProxyKind::Approve);

        $this->assertSame([self::BY_B], $this->listedFor($b));
        $this->get('/workflow/flows/'.self::BY_A.'/apply')->assertForbidden();
    }

    /**
     * 並びは今のまま(sort_key・code)で、代理元が申請できるフローも同じ並びに入る。
     */
    public function test_the_order_is_unchanged_when_a_principal_adds_flows(): void
    {
        [$a, $b] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->addProxy($a, $b, WorkflowProxyKind::Apply);
        $this->actingAs($b);
        $codes = array_column($this->get('/workflow/flows')->assertOk()->viewData('page')['props']['flows'], 'code');
        $mine = array_values(array_intersect($codes, [self::BY_A, self::BY_B]));

        $this->assertSame([self::BY_A, self::BY_B], $mine);
    }

    /**
     * @return list<string> フローの一覧のうちテストで作ったフローのコード(コードの順)
     */
    private function listedFor(User $user): array
    {
        $this->actingAs($user);
        $codes = array_column($this->get('/workflow/flows')->assertOk()->viewData('page')['props']['flows'], 'code');
        $mine = array_values(array_intersect($codes, [self::BY_A, self::BY_B, self::BY_NOBODY]));
        sort($mine);

        return $mine;
    }
}
