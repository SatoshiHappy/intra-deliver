<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\AddRouteVersion;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DeleteDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\UpdateDraft;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の操作の鍵の順(docs の 設計/ワークフロー/データモデル/直列化と検査.md 6 節「ロックの順」の (1)(2)(4)。(3) 持ち主の口は WorkflowRecordHoldsTest)。
 * 発行した SQL の順と、鍵の文が業務処理のトランザクションの中(レベル 2 以上。テストの TX がレベル 1)で出ることを確かめる(業務データの入れ物の RecordModuleLockTest と同じ形)。
 * 鍵が別の接続を待たせる効き目は、ここでは確かめない(確かめる形 = CommonMasterLockTest の第 2 の接続。案件の行の鍵への適用は docs の 課題「案件操作の同時実行・再送」)。
 */
final class CaseLockOrderTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;

    private const SHARED = 'pg_advisory_xact_lock_shared(';

    /** 排他の勧告ロック(共通マスタの鍵 WritesCommonMaster::COMMON_MASTER_LOCK_KEY も、業務モジュールの定義の鍵もこの形) */
    private const EXCLUSIVE = 'pg_advisory_xact_lock(';

    /** @var list<array{string, array<mixed>, int}> 記録中に発行された SQL と束縛の値とトランザクションのレベル */
    private array $log = [];

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
        DB::listen(function (QueryExecuted $query) {
            if ($this->recording) {
                $this->log[] = [$query->sql, $query->bindings, $query->connection->transactionLevel()];
            }
        });
    }

    /**
     * (1) 既にある案件を書く操作はどれも、案件の行の FOR NO KEY UPDATE(一時保存の削除は FOR UPDATE)を、ほかの書き込みと勧告ロックより前に出す。
     * (4) どの操作も排他の勧告ロック(共通マスタの鍵を含む)を出さない。
     */
    public function test_every_operation_on_an_existing_case_locks_the_case_row_first(): void
    {
        [$a, $b, $c] = [$this->seededUser('u0001'), $this->seededUser('u0002'), $this->seededUser('u0003')];
        $draft = fn () => WorkflowCase::query()->findOrFail(app(CreateDraft::class)->execute($this->contextOf($a), self::FLOW_CODE, new DraftInput(null, null, [], [])));
        $applied = fn () => $this->applyAs($a);
        $sentBack = function () use ($a, $b, $c) {
            $case = $this->applyAs($a);
            $this->operate(ApproveCase::class, $b, $case);
            $this->sendBack($c, $case, 'apply');

            return $case;
        };
        $operations = [
            'overwrite_draft' => [$draft, fn (WorkflowCase $case) => app(UpdateDraft::class)->execute($this->contextOf($a), $case->id, 0, new DraftInput('上書き', null, [], [])), 'for no key update'],
            'delete_draft' => [$draft, fn (WorkflowCase $case) => app(DeleteDraft::class)->execute($this->contextOf($a), $case->id, 0), 'for update'],
            'apply' => [$draft, fn (WorkflowCase $case) => app(ApplyCase::class)->executeFromDraft($this->contextOf($a), $case->id, 0, $this->projectInput(), $this->operationDay()->toDateString()), 'for no key update'],
            'approve' => [$applied, fn (WorkflowCase $case) => $this->operate(ApproveCase::class, $b, $case), 'for no key update'],
            'approve_early' => [$applied, fn (WorkflowCase $case) => $this->operate(ApproveCaseEarly::class, $b, $case), 'for no key update'],
            'reject' => [$applied, fn (WorkflowCase $case) => $this->operate(RejectCase::class, $b, $case), 'for no key update'],
            'send_back' => [$applied, fn (WorkflowCase $case) => $this->sendBack($b, $case, 'apply'), 'for no key update'],
            'pull_back' => [$applied, fn (WorkflowCase $case) => $this->operate(PullBackCase::class, $a, $case), 'for no key update'],
            'reapply' => [$sentBack, fn (WorkflowCase $case) => $this->reapply($a, $case), 'for no key update'],
            'withdraw' => [$sentBack, fn (WorkflowCase $case) => $this->operate(WithdrawCase::class, $a, $case), 'for no key update'],
        ];

        foreach ($operations as $name => [$build, $operation, $lock]) {
            $case = $build();
            $queries = $this->queries(fn () => $operation($case));
            $lockedAt = $this->indexOf($queries, fn (string $sql, array $bindings) => str_contains($sql, 'from "workflow_cases"') && str_ends_with($sql, $lock) && $bindings === [$case->id]);
            $firstWriteAt = $this->indexOf($queries, fn (string $sql) => preg_match('/^(insert|update|delete)\b/', $sql) === 1 || str_contains($sql, 'pg_advisory'));

            $this->assertNotNull($lockedAt, "{$name}: 案件の行の {$lock} が出ていない");
            $this->assertNotNull($firstWriteAt, "{$name}: 書き込みが無い");
            $this->assertLessThan($firstWriteAt, $lockedAt, "{$name}: 案件の行の鍵より前に書き込み・勧告ロックがある");
            $this->assertNoExclusiveLock($queries, $name);
        }
    }

    /**
     * (2) 再申請は 案件の行の FOR NO KEY UPDATE → 業務モジュールの共有の勧告ロック → レコードの行の FOR NO KEY UPDATE の順。
     */
    public function test_reapplying_locks_the_case_then_the_module_then_the_record(): void
    {
        [$a, $b] = [$this->seededUser('u0001'), $this->seededUser('u0002')];
        $case = $this->applyAs($a);
        $this->sendBack($b, $case, 'apply');

        $queries = $this->queries(fn () => $this->reapply($a, $case));

        $caseAt = $this->indexOf($queries, fn (string $sql) => str_contains($sql, 'from "workflow_cases"') && str_ends_with($sql, 'for no key update'));
        $moduleAt = $this->indexOf($queries, fn (string $sql) => str_contains($sql, self::SHARED));
        $recordAt = $this->indexOf($queries, fn (string $sql, array $bindings) => str_contains($sql, 'from "records"') && str_ends_with($sql, 'for no key update') && in_array($case->record_id, $bindings, true));

        $this->assertNotNull($caseAt);
        $this->assertNotNull($moduleAt);
        $this->assertNotNull($recordAt);
        $this->assertLessThan($moduleAt, $caseAt);
        $this->assertLessThan($recordAt, $moduleAt);
    }

    /**
     * (4) 案件の無い操作(一時保存の新規・新規の申請)も排他の勧告ロックを出さない(申請は業務モジュールの共有の勧告ロックだけ)。
     */
    public function test_operations_without_a_case_take_no_exclusive_lock(): void
    {
        $a = $this->seededUser('u0001');

        $this->assertNoExclusiveLock($this->queries(fn () => app(CreateDraft::class)->execute($this->contextOf($a), self::FLOW_CODE, new DraftInput(null, null, [], []))), 'save_draft');
        $applied = $this->queries(fn () => $this->applyAs($a));

        $this->assertNoExclusiveLock($applied, 'apply');
        $this->assertNotNull($this->indexOf($applied, fn (string $sql) => str_contains($sql, self::SHARED)));
    }

    /**
     * ルートの深さの (1)(docs の 設計/ワークフロー/ルートの深さ/データモデル/直列化と検査.md 7 節「ロックの順」): 申請(新規・一時保存から)は、案件の行の鍵
     * (一時保存から)の後に、業務データの入れ物の勧告ロックより前に、フローの行の FOR SHARE を出す。
     */
    public function test_applying_takes_the_flow_row_for_share_after_the_case_row_and_before_the_module_lock(): void
    {
        $a = $this->seededUser('u0001');
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;
        $isFlowShare = fn (string $sql, array $bindings) => str_contains($sql, 'from "workflow_flows"') && str_ends_with($sql, 'for share');
        $draft = WorkflowCase::query()->findOrFail(app(CreateDraft::class)->execute($this->contextOf($a), self::FLOW_CODE, new DraftInput(null, null, [], [])));

        $applied = $this->queries(fn () => $this->applyAs($a));
        $fromDraft = $this->queries(fn () => app(ApplyCase::class)->executeFromDraft($this->contextOf($a), $draft->id, 0, $this->projectInput(), $this->operationDay()->toDateString()));

        foreach (['apply' => $applied, 'apply_from_draft' => $fromDraft] as $name => $queries) {
            $flowAt = $this->indexOf($queries, $isFlowShare);
            $moduleAt = $this->indexOf($queries, fn (string $sql) => str_contains($sql, self::SHARED));
            $this->assertNotNull($flowAt, "{$name}: フローの行の FOR SHARE が出ていない");
            $this->assertNotNull($moduleAt, "{$name}: 業務モジュールの勧告ロックが出ていない");
            $this->assertLessThan($moduleAt, $flowAt, "{$name}: フローの行の鍵より前に業務モジュールの勧告ロックがある");
            $this->assertNotSame([], array_intersect([$flowId, self::FLOW_CODE], $queries[$flowAt][1]), "{$name}: 申請するフローの行でない");
        }

        $caseAt = $this->indexOf($fromDraft, fn (string $sql) => str_contains($sql, 'from "workflow_cases"') && str_ends_with($sql, 'for no key update'));
        $this->assertLessThan($this->indexOf($fromDraft, $isFlowShare), $caseAt);
    }

    /**
     * ルートの深さの (2): 定義の管理の書き込み(版の追加)は、最初にフローの行の FOR NO KEY UPDATE を出し、業務モジュールの勧告ロックを出さない。
     */
    public function test_adding_a_version_locks_the_flow_row_first_without_module_locks(): void
    {
        $queries = $this->queries(fn () => app(AddRouteVersion::class)->execute(self::FLOW_CODE, CarbonImmutable::parse('2026-01-01'), [
            new RouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::User, $this->seededUser('u0001')->id)], ['ja' => '申請']),
            new RouteNodeInput('approval1', '承認1', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::User, $this->seededUser('u0002')->id)], ['ja' => '承認1'], sendBackCodes: ['apply']),
        ], [new RouteEdgeInput('apply', 'approval1')]));
        $firstLockAt = $this->indexOf($queries, fn (string $sql) => preg_match('/ for (no key )?(update|share)$/D', $sql) === 1 || str_contains($sql, 'pg_advisory'));

        $this->assertNotNull($firstLockAt);
        $this->assertStringContainsString('from "workflow_flows"', $queries[$firstLockAt][0]);
        $this->assertStringEndsWith('for no key update', $queries[$firstLockAt][0]);
        $this->assertSame([], $this->indicesOf($queries, fn (string $sql) => str_contains($sql, self::SHARED)));
    }

    /**
     * ルートの深さの (3): 処理(承認・承認終了・否認・差戻し・再申請・取止め)はフローの行の鍵を出さない(準備の申請は記録の外)。
     */
    public function test_processing_takes_no_lock_on_the_flow_row(): void
    {
        [$a, $b, $c] = [$this->seededUser('u0001'), $this->seededUser('u0002'), $this->seededUser('u0003')];
        $applied = fn () => $this->applyAs($a);
        $approved = function () use ($a, $b) {
            $case = $this->applyAs($a);
            $this->operate(ApproveCase::class, $b, $case);

            return $case;
        };
        $sentBack = function () use ($a, $b) {
            $case = $this->applyAs($a);
            $this->sendBack($b, $case, 'apply');

            return $case;
        };
        $operations = [
            'approve' => [$applied, fn (WorkflowCase $case) => $this->operate(ApproveCase::class, $b, $case)],
            'approve_early' => [$applied, fn (WorkflowCase $case) => $this->operate(ApproveCaseEarly::class, $b, $case)],
            'reject' => [$applied, fn (WorkflowCase $case) => $this->operate(RejectCase::class, $b, $case)],
            'send_back' => [$approved, fn (WorkflowCase $case) => $this->sendBack($c, $case, 'apply')],
            'reapply' => [$sentBack, fn (WorkflowCase $case) => $this->reapply($a, $case)],
            'withdraw' => [$sentBack, fn (WorkflowCase $case) => $this->operate(WithdrawCase::class, $a, $case)],
        ];

        foreach ($operations as $name => [$build, $operation]) {
            $case = $build();
            $queries = $this->queries(fn () => $operation($case));

            $this->assertNotSame([], $queries, $name);
            $this->assertSame([], $this->indicesOf($queries, fn (string $sql) => str_contains($sql, 'from "workflow_flows"') && preg_match('/ for (no key )?(update|share)$/D', $sql) === 1), "{$name}: フローの行の鍵が出た");
        }
    }

    /**
     * @param  Closure(): mixed  $action
     * @return list<array{string, array<mixed>, int}>
     */
    private function queries(Closure $action): array
    {
        $this->log = [];
        $this->recording = true;

        try {
            $action();
        } finally {
            $this->recording = false;
        }

        $queries = array_map(fn (array $query) => [strtolower($query[0]), $query[1], $query[2]], $this->log);
        $this->assertLocksInsideTheOperationTransaction($queries);

        return $queries;
    }

    /**
     * 鍵の文(行の鍵と勧告ロック)は業務処理の DB::transaction の中で出す。外で取ると、本番では文の終わりに鍵が外れる。
     *
     * @param  list<array{string, array<mixed>, int}>  $queries
     */
    private function assertLocksInsideTheOperationTransaction(array $queries): void
    {
        foreach ($queries as [$sql, , $level]) {
            if (preg_match('/ for (no key )?(update|share)$/D', $sql) === 1 || str_contains($sql, 'pg_advisory')) {
                $this->assertGreaterThanOrEqual(2, $level, "鍵の文 {$sql} がテストの TX(レベル 1)で出た = 業務処理の DB::transaction の外で鍵を取っている");
            }
        }
    }

    /**
     * @param  list<array{string, array<mixed>, int}>  $queries
     */
    private function assertNoExclusiveLock(array $queries, string $name): void
    {
        $this->assertSame([], $this->indicesOf($queries, fn (string $sql) => str_contains($sql, self::EXCLUSIVE)), "{$name}: 排他の勧告ロックが出た");
    }

    /**
     * @param  list<array{string, array<mixed>, int}>  $queries
     * @param  Closure(string, array<mixed>): bool  $matches
     */
    private function indexOf(array $queries, Closure $matches): ?int
    {
        return $this->indicesOf($queries, $matches)[0] ?? null;
    }

    /**
     * @param  list<array{string, array<mixed>, int}>  $queries
     * @param  Closure(string, array<mixed>): bool  $matches
     * @return list<int>
     */
    private function indicesOf(array $queries, Closure $matches): array
    {
        return array_keys(array_filter($queries, fn (array $query) => $matches($query[0], $query[1])));
    }
}
