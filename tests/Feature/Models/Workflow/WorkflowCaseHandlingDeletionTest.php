<?php

namespace Tests\Feature\Models\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowProxy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の運用で足した表の削除の向き(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.13 節、同じ文書群の README.md の 7 節):
 * 申請した案件を消すと、履歴の人・確認対象者・確認も連鎖で消える(互いを RESTRICT で指していても)。代理の行が指すユーザー・フローは消せない。
 * 外部キーごとの削除の動作の照合は tests/Feature/Models/ForeignKeysTest.php。ここは業務の場面ごとに確かめる。
 */
final class WorkflowCaseHandlingDeletionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_deleting_an_applied_case_deletes_its_history_users_confirm_targets_and_confirmations(): void
    {
        $confirmation = WorkflowCaseConfirmation::factory()->createOne();
        $caseId = $confirmation->workflow_case_id;
        WorkflowCaseHistoryUser::factory()->createOne([
            'workflow_case_id' => $caseId,
            'workflow_case_history_id' => $confirmation->workflow_case_history_id,
        ]);

        WorkflowCase::query()->whereKey($caseId)->delete();

        $this->assertSame([0, 0, 0], [
            WorkflowCaseHistoryUser::query()->where('workflow_case_id', $caseId)->count(),
            WorkflowCaseConfirmTarget::query()->where('workflow_case_id', $caseId)->count(),
            WorkflowCaseConfirmation::query()->where('workflow_case_id', $caseId)->count(),
        ]);
    }

    /**
     * 代理の行が指す列 → 指される表・拒む外部キー。
     *
     * @return array<string, array{string, string, string}>
     */
    public static function pointedRows(): array
    {
        return [
            '代理元' => ['principal_user_id', 'users', 'workflow_proxies_principal_user_id_foreign'],
            '代理先' => ['proxy_user_id', 'users', 'workflow_proxies_proxy_user_id_foreign'],
            '登録した人' => ['registered_by_user_id', 'users', 'workflow_proxies_registered_by_user_id_foreign'],
            'フロー' => ['workflow_flow_id', 'workflow_flows', 'workflow_proxies_workflow_flow_id_foreign'],
        ];
    }

    #[DataProvider('pointedRows')]
    public function test_rejects_deleting_a_row_that_a_proxy_points_to(
        string $column, string $table, string $constraint,
    ): void {
        $proxy = WorkflowProxy::factory()->forFlow()->createOne();
        $pointed = $proxy->getAttribute($column);

        $this->assertRejectedBy($constraint, fn () => DB::table($table)->where('id', $pointed)->delete());
    }
}
