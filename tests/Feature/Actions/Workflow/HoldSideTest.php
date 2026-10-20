<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\ReleaseCase;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\TransferCase;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use Closure;
use Tests\Support\OperatesHolds;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 保留者側(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 3.1 節の「保留者側に入る人」と 4.3 節、操作.md の 10.2 節):
 * 本人が保留 → 本人と本人の代理先(本人として)。代理先が代理元として保留 → 代理元本人と代理元の代理先(代理元として。保留した代理先を含む)。
 * 保留中の承認・承認終了・否認・差戻し・振替・保留解除ができ、保留していない処理対象者(その人自身として)と代理先自身としては 422 case。
 * 保留解除の履歴の処理者 = P、申請者承認防止を掛けない、解除の後は全部の処理対象者が承認できる。
 * ルート = 申請 → pair(u0002・u0003)→ final(u0010)。u0002 の承認の代理先 = u0008・u0009。
 */
final class HoldSideTest extends TransactionalTestCase
{
    use OperatesHolds;
    use RegistersProxies;

    private const NOT_PERMITTED = ['case' => 'この申請は今この操作をできません'];

    /** 保留中に保留者側ができる操作 */
    private const OPERATIONS = ['approve', 'approve_early', 'reject', 'send_back', 'transfer', 'release'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $principal = $this->seededUser('u0002');
        $this->addProxy($principal, $this->seededUser('u0008'));
        $this->addProxy($principal, $this->seededUser('u0009'));
        $pair = new RouteNodeInput(
            'pair',
            'pair',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            ['ja' => 'pair'],
            sendBackCodes: ['apply'],
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'pair']);
        $this->addVersion([[$this->applyNode(), $pair, $final], $this->chain(['apply', 'pair'], ['pair', 'final'])]);
    }

    /**
     * u0002 本人が保留: u0002 と u0002 の代理先 u0008(u0002 として)が保留者側。u0003 と u0008 自身としては 422 case。
     */
    public function test_the_holder_and_its_proxies_as_the_holder_are_the_hold_side(): void
    {
        [$case] = $this->applyOnRoute([]);
        $this->holdOn($case, 'pair', 'u0002');

        $this->assertSame([
            'u0002' => array_fill_keys(self::OPERATIONS, 'success'),
            'u0008 as u0002' => array_fill_keys(self::OPERATIONS, 'success'),
            'u0003' => array_fill_keys(self::OPERATIONS, self::NOT_PERMITTED),
            'u0008' => array_fill_keys(self::OPERATIONS, self::NOT_PERMITTED),
        ], [
            'u0002' => $this->outcomes($case, 'u0002', null),
            'u0008 as u0002' => $this->outcomes($case, 'u0008', 'u0002'),
            'u0003' => $this->outcomes($case, 'u0003', null),
            'u0008' => $this->outcomes($case, 'u0008', null),
        ]);
    }

    /**
     * 代理先 u0008 が u0002 として保留(保留の行の処理者 = u0002、実行者 = u0008): u0002 本人・u0008(u0002 として)・ほかの代理先 u0009(u0002 として)が
     * 保留者側。u0008 自身として・u0003 は 422 case。
     */
    public function test_a_hold_as_the_principal_makes_the_principal_and_all_its_proxies_the_hold_side(): void
    {
        [$case] = $this->applyOnRoute([]);
        $this->holdOn($case, 'pair', 'u0008', 'u0002');

        $this->assertSame(['hold', 'u0002', 'u0008', [], []], $this->latestRow($case));
        $this->assertSame([
            'u0002' => array_fill_keys(self::OPERATIONS, 'success'),
            'u0008 as u0002' => array_fill_keys(self::OPERATIONS, 'success'),
            'u0009 as u0002' => array_fill_keys(self::OPERATIONS, 'success'),
            'u0008' => array_fill_keys(self::OPERATIONS, self::NOT_PERMITTED),
            'u0003' => array_fill_keys(self::OPERATIONS, self::NOT_PERMITTED),
        ], [
            'u0002' => $this->outcomes($case, 'u0002', null),
            'u0008 as u0002' => $this->outcomes($case, 'u0008', 'u0002'),
            'u0009 as u0002' => $this->outcomes($case, 'u0009', 'u0002'),
            'u0008' => $this->outcomes($case, 'u0008', null),
            'u0003' => $this->outcomes($case, 'u0003', null),
        ]);
    }

    /**
     * 保留解除の履歴の処理者 = P(代理先が代理元として解除すれば処理者 = 代理元、実行者 = 代理先)。解除の後は保留していない u0003 も承認できる。
     */
    public function test_a_release_records_the_processor_and_every_target_can_approve_after_it(): void
    {
        [$case] = $this->applyOnRoute([]);
        $this->holdOn($case, 'pair', 'u0002');

        $this->releaseOn($case, 'pair', 'u0009', 'u0002');

        $this->assertSame(['release', 'u0002', 'u0009', [], []], $this->latestRow($case));
        $this->assertSame('waiting[u0002,u0003]', $this->shape($case)['pair']);
        $this->approve($case, 'pair', 'u0003');
        $this->assertSame(['done', 'waiting[u0010]'], array_values($this->pick($case, 'pair', 'final')));
    }

    /**
     * 保留解除に申請者承認防止を掛けない: 防止オンの pair を u0003 が保留した後に u0003 を申請者 u0001 の代理先にすると、u0003 は保留中の承認を
     * 拒まれる(代理先の文)が、解除はできる。
     */
    public function test_a_release_is_not_subject_to_applicant_approval_prevention(): void
    {
        $pair = new RouteNodeInput(
            'guarded',
            'guarded',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            ['ja' => 'guarded'],
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'guarded']);
        $edges = $this->chain(['apply', 'guarded'], ['guarded', 'final']);
        $this->addVersion([[$this->applyNode(), $pair, $final], $edges], '2026-02-01');
        [$case] = $this->applyOnRoute([]);
        $this->holdOn($case, 'guarded', 'u0003');
        $this->addProxy($this->seededUser('u0001'), $this->seededUser('u0003'), WorkflowProxyKind::Apply);

        $approved = $this->attemptOf(fn () => $this->approve($case, 'guarded', 'u0003'));
        $this->releaseOn($case, 'guarded', 'u0003');

        $this->assertSame(['case' => '申請者の代理先に設定されているため、この申請を承認できません。このステップには、申請者承認防止が設定されています。'], $approved);
        $this->assertSame('waiting[u0002,u0003]', $this->shape($case)['guarded']);
    }

    /**
     * 保留者側ができる操作のそれぞれを savepoint の中で行った結果。
     *
     * @return array<string, string|array<string, string>>
     */
    private function outcomes(WorkflowCase $case, string $userCode, ?string $principalCode): array
    {
        $context = $this->contextOf($this->seededUser($userCode));
        $principal = $principalCode === null ? null : $this->seededUser($principalCode)->id;
        $ids = $this->labelIds($case);
        $call = fn (Closure $operate) => $this->attemptOf(fn () => $operate($this->revisionOf($case), $ids['pair']));

        return [
            'approve' => $call(fn (int $revision, int $node) => app(ApproveCase::class)
                ->execute($context, $case->id, $revision, $node, null, principalUserId: $principal)),
            'approve_early' => $call(fn (int $revision, int $node) => app(ApproveCaseEarly::class)
                ->execute($context, $case->id, $revision, $node, null, $principal)),
            'reject' => $call(fn (int $revision, int $node) => app(RejectCase::class)
                ->execute($context, $case->id, $revision, $node, null, $principal)),
            'send_back' => $call(fn (int $revision, int $node) => app(SendBackCase::class)
                ->execute($context, $case->id, $revision, $node, $ids['apply'], null, $principal)),
            'transfer' => $call(fn (int $revision, int $node) => app(TransferCase::class)
                ->execute($context, $case->id, $revision, $node, $this->seededUser('u0006')->id, null, $principal)),
            'release' => $call(fn (int $revision, int $node) => app(ReleaseCase::class)
                ->execute($context, $case->id, $revision, $node, null, $principal)),
        ];
    }
}
