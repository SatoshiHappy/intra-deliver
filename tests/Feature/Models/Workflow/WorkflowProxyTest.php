<?php

namespace Tests\Feature\Models\Workflow;

use App\Models\Workflow\WorkflowProxy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 代理の制約(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.12 節、同じ文書群の README.md の 7 節): 代理元 ≠ 代理先、
 * 期間は半開区間(長さ 0 なし・infinity なし)。同じ組の重なりは許す(一意・排他制約を置かない)。代理の別の値の一覧は WorkflowEnumValuesTest が確かめる。
 */
final class WorkflowProxyTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const PERIOD = 'workflow_proxies_start_date_end_date_check';

    public function test_rejects_the_principal_as_the_proxy(): void
    {
        $proxy = WorkflowProxy::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_proxies_proxy_user_id_principal_user_id_check',
            fn () => $proxy->update(['proxy_user_id' => $proxy->principal_user_id]),
        );
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function invalidPeriods(): array
    {
        return [
            '開始日 = 終了日' => ['2026-10-01', '2026-10-01'],
            '開始日 > 終了日' => ['2026-10-02', '2026-10-01'],
            '開始日が -infinity' => ['-infinity', null],
            '終了日が infinity' => ['2026-10-01', 'infinity'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_rejects_an_invalid_period(string $startDate, ?string $endDate): void
    {
        $proxy = WorkflowProxy::factory()->createOne();

        // infinity は日付のキャストで読めないので、列の値を SQL で書く
        $this->assertRejectedBy(
            self::PERIOD,
            fn () => DB::table('workflow_proxies')->where('id', $proxy->id)
                ->update(['start_date' => $startDate, 'end_date' => $endDate]),
        );
    }

    public function test_accepts_two_overlapping_rows_of_the_same_pair_kind_and_flow(): void
    {
        $first = WorkflowProxy::factory()->forFlow()
            ->createOne(['start_date' => '2026-10-01', 'end_date' => '2026-11-01']);
        $second = WorkflowProxy::factory()->createOne([
            ...$first->only(['principal_user_id', 'proxy_user_id', 'kind', 'workflow_flow_id']),
            'start_date' => '2026-10-15',
            'end_date' => null,
        ]);

        $this->assertModelExists($second);
        $this->assertSame(2, WorkflowProxy::query()->where('principal_user_id', $first->principal_user_id)->count());
    }
}
