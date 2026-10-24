<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Records\Api\DeleteRecordUnchecked;
use App\Actions\Workflow\CaseManagerGate;
use App\Actions\Workflow\CaseOperationPermit;
use App\Actions\Workflow\DeleteCase;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseSituation;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の削除のデッドロックのやり直し(docs の 設計/ワークフロー/データモデル/直列化と検査.md の 3.2 節の案件の削除の箇条、
 * 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.7 節): 一番外のトランザクションで 3 回まで試し、巻き戻った試しが
 * 頼んだコミットの後の後始末(添付の実体の削除)は走らない。入れ子のときはやり直さずに伝える。口が入れ子の層で失敗する経路も、一番外がやり直す。
 * テストのトランザクションの中では一番外にならないので、一番外の場合は同じ設定の別の接続を既定にして呼ぶ。判定と判定しない削除の口は
 * 偽物にして、別の接続では行を読み書きしない(試しごとの結果を口の偽物が決める)。
 */
final class DeleteCaseRetryTest extends TransactionalTestCase
{
    /** 一番外のトランザクションにする、既定と同じ設定の別の接続 */
    private const OUTERMOST = 'delete_case_outermost';

    /** @var list<int> コミットの後の後始末が走った試し */
    private array $cleanedUp = [];

    private int $tries = 0;

    /**
     * 試しごとの口の結果(deadlock = デッドロック、reject = 参照されている 422、done = 消せた)、期待する試しの回数と後始末と結果。
     *
     * @return array<string, array{list<string>, int, list<int>, string}>
     */
    public static function outcomes(): array
    {
        return [
            'デッドロックの後に消せた' => [['deadlock', 'done'], 2, [2], 'done'],
            'デッドロックの後に参照されている 422' => [['deadlock', 'reject'], 2, [], 'reject'],
            '3 回ともデッドロック' => [['deadlock', 'deadlock', 'deadlock'], 3, [], 'deadlock'],
        ];
    }

    /**
     * @param  list<string>  $results
     * @param  list<int>  $cleanedUp
     */
    #[DataProvider('outcomes')]
    public function test_the_outermost_transaction_retries_a_deadlock_up_to_3_times(
        array $results,
        int $tries,
        array $cleanedUp,
        string $outcome,
    ): void {
        $this->fakeGateAndPort($results);

        $this->assertSame([$tries, $cleanedUp, $outcome, 0], $this->runOnOutermostConnection());
    }

    /**
     * 本物の口は自分の DB::transaction(入れ子の層)の中で失敗するので、デッドロックは DeadlockException に変わってから一番外に届く。
     * それでも一番外がやり直して成功し、巻き戻った入れ子の層が頼んだ後始末は走らない。
     */
    public function test_a_deadlock_that_passes_through_a_nested_layer_is_retried_by_the_outermost_transaction(): void
    {
        $this->fakeGateAndPort(['deadlock', 'done'], nestedPort: true);

        $this->assertSame([2, [2], 'done', 0], $this->runOnOutermostConnection());
    }

    /**
     * 入れ子の層を通るデッドロックが 3 回とも続くと、やり直しが尽きて DeadlockException のまま届き、後始末は走らない。
     */
    public function test_a_deadlock_through_a_nested_layer_that_lasts_for_every_attempt_is_passed_on(): void
    {
        $this->fakeGateAndPort(['deadlock', 'deadlock', 'deadlock'], nestedPort: true);

        $this->assertSame([3, [], 'deadlock', 0], $this->runOnOutermostConnection());
    }

    /**
     * ほかのトランザクション(ここではテストのもの)の中で呼ぶと、デッドロックは 1 回で DeadlockException として伝わり、後始末は走らない。
     */
    public function test_inside_another_transaction_a_deadlock_is_passed_on_without_retrying(): void
    {
        $this->fakeGateAndPort(['deadlock', 'done']);

        $this->assertThrows(fn () => app(DeleteCase::class)->execute($this->context(), 1, 0), DeadlockException::class);
        $this->assertSame([1, []], [$this->tries, $this->cleanedUp]);
    }

    /**
     * 既定と同じ設定の別の接続を既定にして(= 一番外のトランザクションにして)案件の削除を呼ぶ。
     *
     * @return array{int, list<int>, string, int} 試しの回数・コミットの後の後始末が走った試し・結果・終わった後のトランザクションの深さ
     */
    private function runOnOutermostConnection(): array
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.'.self::OUTERMOST => config("database.connections.{$default}")]);
        DB::setDefaultConnection(self::OUTERMOST);

        try {
            $actual = $this->outcomeOf(fn () => app(DeleteCase::class)->execute($this->context(), 1, 0));
            $level = DB::transactionLevel();
        } finally {
            DB::setDefaultConnection($default);
            DB::purge(self::OUTERMOST);
        }

        return [$this->tries, $this->cleanedUp, $actual, $level];
    }

    /**
     * 判定は鍵を取らない偽物(DB に無い案件。消しても文を出さない)、口は試しごとにコミットの後の後始末を頼んでから $results の結果を返す偽物。
     * $nestedPort が真なら、口は本物と同じく自分の DB::transaction の中でそれをする。
     *
     * @param  list<string>  $results
     */
    private function fakeGateAndPort(array $results, bool $nestedPort = false): void
    {
        $case = (new WorkflowCase)->forceFill(['id' => 1, 'record_id' => 1]);
        $permit = new CaseOperationPermit(new CaseSituation($case, [], [], [], null, []), null, null);
        $this->mock(CaseManagerGate::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('lockAndPermit')->andReturn($permit));
        $attempt = function () use ($results): void {
            $try = ++$this->tries;
            DB::afterCommit(function () use ($try): void {
                $this->cleanedUp[] = $try;
            });

            match ($results[$try - 1]) {
                'deadlock' => throw self::deadlock(),
                'reject' => throw ValidationException::withMessages(['record' => ['参照されている']]),
                default => null,
            };
        };
        $this->mock(DeleteRecordUnchecked::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('execute')->with(1)->andReturnUsing(
                fn () => $nestedPort ? DB::transaction($attempt) : $attempt(),
            ));
    }

    /**
     * @param  callable(): void  $action
     */
    private function outcomeOf(callable $action): string
    {
        try {
            $action();

            return 'done';
        } catch (ValidationException) {
            return 'reject';
        } catch (QueryException|DeadlockException) {
            return 'deadlock';
        }
    }

    private function context(): AccessContext
    {
        return new AccessContext(null, null, null, CarbonImmutable::now(), 'ja');
    }

    /**
     * PostgreSQL のデッドロック(SQLSTATE 40P01)の QueryException。
     */
    private static function deadlock(): QueryException
    {
        $pdo = new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '40P01');

        return new QueryException('pgsql', 'delete from "records" where "id" = ?', [], $pdo);
    }
}
