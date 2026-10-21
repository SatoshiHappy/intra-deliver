<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ReapplyCase;
use App\Models\Workflow\WorkflowCase;
use Closure;
use Tests\Support\OperatesStepAttachments;
use Tests\Support\TransactionalTestCase;

/**
 * 添付を書く操作の鍵の順(docs の 設計/ワークフロー/申請の周辺/データモデル.md の 4 節、呼ぶ順 = 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節):
 * 台帳の鍵(StoreUploads の排他の勧告ロック)は、どの操作でも案件の行の鍵(一時保存の新規は案件の行を書いた後)より後で、
 * 申請の経路では業務データの入れ物の口の中(業務モジュールの共有の勧告ロックの後)で取る。ワークフローが先に実体を上げない。
 * ステップの処理は、判定と鍵の後・履歴の行を書く前に口を呼ぶ。
 */
final class AttachmentLockOrderTest extends TransactionalTestCase
{
    use OperatesStepAttachments;

    private const LEDGER_LOCK = 'select pg_advisory_xact_lock(?)';

    private const MODULE_LOCK = 'select pg_advisory_xact_lock_shared(?)';

    private const CASE_LOCK = '/from "workflow_cases" where "workflow_cases"\."id" = \? limit 1 for no key update$/D';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    public function test_the_drafts_take_the_ledger_lock_after_the_case_row(): void
    {
        $u0001 = $this->seededUser('u0001');
        $created = $this->recordedSql(fn () => $this->draftWith($u0001, [$this->uploadedFile('a.pdf')]));
        $draft = WorkflowCase::query()->latest('id')->firstOrFail();
        $overwritten = $this->recordedSql(fn () => $this->overwrite($u0001, $draft, [$this->uploadedFile('b.pdf')]));

        $this->assertOrder($created, [fn (string $sql) => str_starts_with($sql, 'insert into "workflow_cases"')]);
        $this->assertOrder($overwritten, [fn (string $sql) => preg_match(self::CASE_LOCK, $sql) === 1]);
    }

    public function test_the_applications_take_the_ledger_lock_inside_the_record_port(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draft = $this->draftWith($u0001, [$this->uploadedFile('a.pdf')]);
        $isCaseLock = fn (string $sql) => preg_match(self::CASE_LOCK, $sql) === 1;
        $isFlowLock = fn (string $sql) => str_contains($sql, 'from "workflow_flows"')
            && str_ends_with($sql, 'for share');
        $isModuleLock = fn (string $sql) => $sql === self::MODULE_LOCK;

        $fromDraft = $this->recordedSql(fn () => $this->applyFromDraft($u0001, $draft, [$this->uploadedFile('b.pdf')]));
        $case = WorkflowCase::query()->findOrFail($draft->id);
        $new = $this->recordedSql(fn () => $this->applyWith($u0001, [$this->uploadedFile('c.pdf')]));
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');
        $input = $this->attachmentInput([$this->uploadedFile('d.pdf')]);
        $reapplied = $this->recordedSql(fn () => app(ReapplyCase::class)
            ->execute($this->contextOf($u0001), $case->id, $this->revisionOf($case), $input));
        $isRecordLock = fn (string $sql) => str_contains($sql, 'from "records"')
            && str_ends_with($sql, 'for no key update');

        $this->assertOrder($fromDraft, [$isCaseLock, $isFlowLock, $isModuleLock]);
        $this->assertOrder($new, [$isFlowLock, $isModuleLock]);
        $this->assertOrder($reapplied, [$isCaseLock, $isModuleLock, $isRecordLock]);
    }

    /**
     * 承認(計画を書く処理)と保留解除(計画を呼ばない処理): 案件の行 → 業務モジュールの共有 → レコードの行 → 台帳 → 実体の行 → 履歴の行。
     */
    public function test_the_steps_call_the_record_port_after_the_case_lock_and_before_the_history(): void
    {
        $isCaseLock = fn (string $sql) => preg_match(self::CASE_LOCK, $sql) === 1;
        $isModuleLock = fn (string $sql) => $sql === self::MODULE_LOCK;
        $isRecordLock = fn (string $sql) => str_contains($sql, 'from "records"')
            && str_ends_with($sql, 'for no key update');
        $isUpload = fn (string $sql) => str_starts_with($sql, 'insert into "uploads"');
        $isHistory = fn (string $sql) => str_starts_with($sql, 'insert into "workflow_case_histories"');

        foreach (['approve', 'release'] as $name) {
            [$case, $user] = $this->caseReadyFor($name);
            $files = [$this->uploadedFile("{$name}.pdf")];
            $queries = $this->recordedSql(fn () => $this->step($name, $user, $case, $files));

            $this->assertOrder($queries, [$isCaseLock, $isModuleLock, $isRecordLock]);
            $this->assertCount(1, array_filter($queries, $isHistory), $name);
            $historyAt = $this->firstIndex($queries, $isHistory);
            $this->assertLessThan($historyAt, $this->firstIndex($queries, $isUpload), $name);
        }
    }

    /**
     * 鍵の文が渡した順に 1 回目が出て、台帳の鍵がその後に 1 回だけ出る。実体の行(uploads)を書くのは台帳の鍵の後だけ。
     *
     * @param  list<string>  $queries
     * @param  list<Closure(string): bool>  $before
     */
    private function assertOrder(array $queries, array $before): void
    {
        $ledger = array_keys($queries, self::LEDGER_LOCK, true);
        $sequence = [
            ...array_map(fn (Closure $match) => $this->firstIndex($queries, $match), $before),
            $ledger[0] ?? null,
            $this->firstIndex($queries, fn (string $sql) => str_starts_with($sql, 'insert into "uploads"')),
        ];

        $this->assertCount(1, $ledger, '台帳の鍵が 1 回でない');
        $this->assertNotContains(null, $sequence, '鍵の文か実体の行が出ていない');
        $sorted = array_values(array_unique($sequence));
        sort($sorted);
        $this->assertSame($sorted, $sequence, '鍵・台帳の鍵・実体の行の順が違う');
    }

    /**
     * @param  list<string>  $queries
     * @param  Closure(string): bool  $match
     */
    private function firstIndex(array $queries, Closure $match): ?int
    {
        foreach ($queries as $index => $sql) {
            if ($match($sql)) {
                return $index;
            }
        }

        return null;
    }
}
