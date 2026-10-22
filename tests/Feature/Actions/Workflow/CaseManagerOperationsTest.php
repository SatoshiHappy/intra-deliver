<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\GrantsFlowPermissions;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の管理者の操作の判定(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節・15.2 節、操作.md の 10.2 節):
 * 管理者の保留解除(principal_user_id = null。処理者 = 管理者、実行者 = null。申請者承認防止を掛けない)、operate の 2 段の業務処理の側
 * (閲覧できない人は 404 が 403 より先、operate を許されない閲覧できる人は 403)、案件操作の行の後の引戻し。管理者 = システム管理者 u0004。
 * ルート = 申請 → pair(u0002・u0003)→ final(u0010)。参照者 = 経理部(u0010)。
 */
final class CaseManagerOperationsTest extends TransactionalTestCase
{
    use GrantsFlowPermissions;
    use OperatesAsCaseManager;

    private const NOT_PERMITTED = ['case' => 'この申請は今この操作をできません'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 管理者は保留者側でなくても自分として解除できる(履歴の処理者 = 管理者、実行者 = null)。保留者側でも管理者でもない処理対象者 u0003 は 422 case。
     */
    public function test_a_case_manager_releases_a_hold_as_himself(): void
    {
        $this->addPairRoute(false);
        [$case] = $this->applyOnRoute([]);
        $this->holdOn($case, 'pair', 'u0002');

        $byTarget = $this->attemptOf(fn () => $this->releaseOn($case, 'pair', 'u0003'));
        $this->releaseOn($case, 'pair', self::MANAGER);

        $this->assertSame(self::NOT_PERMITTED, $byTarget);
        $this->assertSame(['release', self::MANAGER, null, [], []], $this->latestRow($case));
        $this->assertSame('waiting[u0002,u0003]', $this->shape($case)['pair']);
    }

    /**
     * 申請者承認防止のノードでも、申請者である管理者が解除できる(管理者の操作に申請者承認防止を掛けない)。
     */
    public function test_a_case_manager_who_is_the_applicant_releases_a_preventing_node(): void
    {
        $this->addPairRoute(true);
        $context = $this->contextOf($this->seededUser(self::MANAGER));
        $applied = app(ApplyCase::class)->execute($context, self::FLOW_CODE, $this->projectInput(), '2026-10-13');
        $case = WorkflowCase::query()->findOrFail($applied->caseId);
        $this->holdOn($case, 'pair', 'u0002');

        $this->releaseOn($case, 'pair', self::MANAGER);

        $this->assertSame(['release', self::MANAGER, null, [], []], $this->latestRow($case));
    }

    /**
     * 案件操作の 7 つ: 閲覧できない人(u0008)は 404、参照者 u0010 と read だけを許した u0006 は 403、operate だけを許した閲覧できない u0007 は 404
     * (404 が 403 より先)、read と operate を許した u0009 は通る。
     */
    public function test_operate_is_judged_after_visibility(): void
    {
        $this->addPairRoute(false);
        $this->grantFlow($this->seededUser('u0006'), self::FLOW_CODE, WorkflowFlowAction::Read);
        $this->grantFlow($this->seededUser('u0007'), self::FLOW_CODE, WorkflowFlowAction::Operate);
        $this->grantFlow($this->seededUser('u0009'), self::FLOW_CODE, WorkflowFlowAction::Read);
        $this->grantFlow($this->seededUser('u0009'), self::FLOW_CODE, WorkflowFlowAction::Operate);
        [$case] = $this->applyOnRoute([]);
        $operations = $this->managerOperations($case);
        $outcomes = [];

        foreach (['u0008', 'u0010', 'u0006', 'u0007', 'u0009'] as $userCode) {
            foreach ($operations as $name => $operate) {
                $outcomes[$userCode][$name] = $this->outcomeOf(fn () => $operate($userCode));
            }
        }

        $expected = fn (string $outcome) => array_fill_keys(array_keys($operations), $outcome);
        $this->assertSame([
            'u0008' => $expected('404'),
            'u0010' => $expected('403'),
            'u0006' => $expected('403'),
            'u0007' => $expected('404'),
            'u0009' => [...$expected('success'), 'reassign' => '422'],
        ], $outcomes);
    }

    /**
     * 処理対象者の変更・再展開の後は、そのノードへ到達させた前のノードの処理者(u0002)は引戻せない(案件操作が子孫を変えた)。案件操作の行は引戻せない
     * (管理者の引戻しは 422 case)。
     */
    public function test_a_manager_operation_blocks_pulling_back_and_cannot_be_pulled_back(): void
    {
        $nodes = [
            $this->applyNode(),
            $this->approveNode('a1', 'u0002', ['apply']),
            $this->approveNode('a2', 'u0003', ['apply', 'a1']),
        ];
        $this->addVersion([$nodes, $this->chain(['apply', 'a1'], ['a1', 'a2'])]);
        [$retargeted] = $this->applyOnRoute([]);
        [$reexpanded] = $this->applyOnRoute([]);
        $errors = [];

        foreach ([$retargeted, $reexpanded] as $case) {
            $this->approve($case, 'a1', 'u0002');
            $case === $retargeted ? $this->retargetOn($case, 'a2', ['u0006']) : $this->reexpandOn($case, 'a2');
            $errors[] = $this->attemptOf(fn () => $this->pullBackOn($case, 'a1', 'u0002'));
            $errors[] = $this->attemptOf(fn () => $this->pullBackOn($case, 'a2', self::MANAGER));
        }

        $this->assertSame(array_fill(0, 4, self::NOT_PERMITTED), $errors);
    }

    /**
     * 案件操作を人のコードで呼ぶ(処理対象者の変更 = pair を u0006 に、戻る = pair から申請へ、指定の変更 = pair(指定のノードでない)、ほかは pair)。
     *
     * @return array<string, Closure(string): mixed>
     */
    private function managerOperations(WorkflowCase $case): array
    {
        return [
            'retarget' => fn (string $code) => $this->retargetOn($case, 'pair', ['u0006'], $code),
            'reexpand' => fn (string $code) => $this->reexpandOn($case, 'pair', $code),
            'move_back' => fn (string $code) => $this->moveOn($case, 'pair', 'back', 'apply', $code),
            'move_forward' => fn (string $code) => $this->moveOn($case, 'pair', 'forward', managerCode: $code),
            'move_finish' => fn (string $code) => $this->moveOn($case, 'pair', 'finish', managerCode: $code),
            'reassign' => fn (string $code) => $this->reassignOn($case, 'pair', ['u0006'], $code),
            'delete_case' => fn (string $code) => $this->deleteCaseAs($case, $code),
        ];
    }

    /**
     * 操作を savepoint の中で行い、成否(success・404・403・422)を返して巻き戻す。
     *
     * @param  Closure(): mixed  $operate
     */
    private function outcomeOf(Closure $operate): string
    {
        try {
            return $this->attemptOf($operate) === 'success' ? 'success' : '422';
        } catch (ModelNotFoundException) {
            return '404';
        } catch (AuthorizationException) {
            return '403';
        }
    }

    /**
     * 申請 → pair(u0002・u0003。$prevents = 申請者承認防止)→ final(u0010)の版。
     */
    private function addPairRoute(bool $prevents): void
    {
        $pair = new RouteNodeInput(
            'pair',
            'pair',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            ['ja' => 'pair'],
            preventsApplicantApproval: $prevents,
            sendBackCodes: ['apply'],
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'pair']);
        $this->addVersion([[$this->applyNode(), $pair, $final], $this->chain(['apply', 'pair'], ['pair', 'final'])]);
    }
}
