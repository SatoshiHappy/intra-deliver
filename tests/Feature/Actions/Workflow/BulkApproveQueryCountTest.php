<?php

namespace Tests\Feature\Actions\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\RunsBulkOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 一括承認の問い合わせの数(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。1 件の判定は、処理の時の組の出し直しと、
 * 単独の承認と同じ処理担当者の確かめと表の判定で、状況は読み直さない。件数に比例して増える量には上限を置く(1 件あたりの多くは処理対象者の展開 = 認可の口)。
 * フロー = 申請(u0001)→ 承認 1(u0002 と u0003)→ 承認 2(u0004)。
 */
final class BulkApproveQueryCountTest extends TransactionalTestCase
{
    use RunsBulkOperations;

    /**
     * 案件 1 件あたりの問い合わせの上限(実測は 2 件と 4 件の差 = 356 = 1 件あたり 178。組の判定と、単独の承認と同じ処理担当者の確かめと
     * 表の判定を通し、状況は読み直さない)
     */
    private const PER_CASE_LIMIT = 200;

    /** 案件の行の鍵(閲覧の判定の前に取る FOR NO KEY UPDATE)の始まりと終わり */
    private const CASE_LOCK_SQL = ['select * from "workflow_cases" where', 'for no key update'];

    /** 読めるフローの判定が最初に読むフローの一覧(閲覧の判定 1 回につき 1 回) */
    private const FLOW_LIST_SQL = 'select "code", "id" from "workflow_flows" order by "id" asc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->createFlow(approvals: [
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            [$this->userCondition('u0004')],
        ]);
    }

    /**
     * 案件ごとに、鍵と閲覧の判定は 1 回(結果の行を作る閲覧の判定が 1 回足される)。
     */
    public function test_each_case_is_locked_and_judged_once(): void
    {
        $user = $this->seededUser('u0002');
        $rows = $this->screenRows($user, 3);

        $sql = $this->recordedSql(fn () => $this->assertSame(3, $this->bulkApprove($user, $rows)->succeeded()));

        [$head, $tail] = self::CASE_LOCK_SQL;
        $isCaseLock = fn (string $query) => str_starts_with($query, $head) && str_ends_with($query, $tail);
        $this->assertCount(3, array_filter($sql, $isCaseLock));
        $this->assertCount(4, array_keys($sql, self::FLOW_LIST_SQL, true));
    }

    /**
     * 2 件から 4 件に増やして増える問い合わせは、1 件あたりの上限の 2 件分まで。
     */
    public function test_queries_grow_within_the_limit_per_case(): void
    {
        $user = $this->seededUser('u0002');
        $this->bulkApprove($user, $this->screenRows($user, 1));
        $count = function (int $cases) use ($user): int {
            $rows = $this->screenRows($user, $cases);

            $approve = fn () => $this->assertSame($cases, $this->bulkApprove($user, $rows)->succeeded());

            return count($this->recordedSql($approve));
        };

        $this->assertLessThanOrEqual(2 * self::PER_CASE_LIMIT, $count(4) - $count(2));
    }

    /**
     * 申請した案件の、画面が送る行。
     *
     * @return list<array{id: int, revision: int, node_id: int, principal_user_id: int|null}>
     */
    private function screenRows(User $user, int $cases): array
    {
        $applied = array_map(fn () => $this->applyAs($this->seededUser('u0001')), range(1, $cases));

        return array_map(fn (WorkflowCase $case) => $this->screenRowOf($case, $user), $applied);
    }
}
