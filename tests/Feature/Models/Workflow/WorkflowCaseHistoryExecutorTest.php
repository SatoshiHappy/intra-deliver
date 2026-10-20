<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCaseHistory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 履歴の実行者と、案件の運用で足した種別の制約(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.7 節、同じ文書群の README.md の 7 節):
 * 実行者 ≠ 処理者、実行者を持てるのは管理の種別でない行、差戻し先を持つのは差戻しと戻る。種別の値の一覧そのものは WorkflowEnumValuesTest が確かめる。
 */
final class WorkflowCaseHistoryExecutorTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const SEND_BACK = 'workflow_case_histories_action_send_back_workflow_case_node_id_check';

    public function test_rejects_the_processor_as_the_executor(): void
    {
        $history = WorkflowCaseHistory::factory()->byProxy()->createOne();

        $this->assertRejectedBy(
            'workflow_case_histories_executor_user_id_user_id_check',
            fn () => $history->update(['executor_user_id' => $history->user_id]),
        );
    }

    /**
     * @return array<string, array{WorkflowAction}>
     */
    public static function managementActions(): array
    {
        return self::byValue([
            WorkflowAction::Retarget, WorkflowAction::Reexpand, WorkflowAction::MoveBack, WorkflowAction::MoveForward,
            WorkflowAction::MoveFinish, WorkflowAction::Reassign,
        ]);
    }

    #[DataProvider('managementActions')]
    public function test_rejects_an_executor_on_a_management_action(WorkflowAction $action): void
    {
        // 戻るは差戻し先を持つ(差戻し先の検査制約に先に拒まれないように)
        $factory = $action === WorkflowAction::MoveBack
            ? WorkflowCaseHistory::factory()->sendBack()
            : WorkflowCaseHistory::factory();

        $this->assertRejectedBy(
            'workflow_case_histories_executor_user_id_action_check',
            fn () => $factory->byProxy()->createOne(['action' => $action]),
        );
    }

    /**
     * @return array<string, array{WorkflowAction}>
     */
    public static function proxyActions(): array
    {
        return self::byValue([
            WorkflowAction::Approve, WorkflowAction::Hold, WorkflowAction::Release, WorkflowAction::Transfer,
        ]);
    }

    #[DataProvider('proxyActions')]
    public function test_accepts_an_executor_on_an_action_that_can_be_done_by_proxy(WorkflowAction $action): void
    {
        $executor = User::factory()->createOne();
        $history = WorkflowCaseHistory::factory()
            ->createOne(['action' => $action, 'executor_user_id' => $executor->id]);

        $this->assertSame([$action, $executor->id], [$history->refresh()->action, $history->executor_user_id]);
    }

    public function test_accepts_a_move_back_with_the_destination(): void
    {
        $history = WorkflowCaseHistory::factory()->sendBack()->createOne(['action' => WorkflowAction::MoveBack]);

        $this->assertSame(WorkflowAction::MoveBack, $history->refresh()->action);
    }

    public function test_rejects_a_move_back_without_the_destination(): void
    {
        $this->assertRejectedBy(
            self::SEND_BACK,
            fn () => WorkflowCaseHistory::factory()->createOne(['action' => WorkflowAction::MoveBack]),
        );
    }

    public function test_rejects_a_destination_on_a_hold(): void
    {
        $this->assertRejectedBy(
            self::SEND_BACK,
            fn () => WorkflowCaseHistory::factory()->sendBack()->createOne(['action' => WorkflowAction::Hold]),
        );
    }

    /**
     * @param  list<WorkflowAction>  $actions
     * @return array<string, array{WorkflowAction}>
     */
    private static function byValue(array $actions): array
    {
        $cases = [];

        foreach ($actions as $action) {
            $cases[$action->value] = [$action];
        }

        return $cases;
    }
}
