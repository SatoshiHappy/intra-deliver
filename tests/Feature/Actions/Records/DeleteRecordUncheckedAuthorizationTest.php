<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\DeleteRecordUnchecked;
use App\Actions\Records\DeleteRecord;
use App\Actions\Records\LockedRecordDeletion;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use Closure;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\TransactionalTestCase;

/**
 * 判定しない削除は判定の入力を受けず、業務モジュールの判定(delete)と行の判定をしない(docs の 設計/ワークフロー/業務データとの接続/公開の口.md
 * 7 節の表の行と「主体の判定をしない口」「行の権限」の項)。判定する削除が断る主体の文脈でも消え、認可と行の判定の SQL が出ない。
 * 外側のトランザクションの中では、デッドロックを口でやり直さずに伝える(同じ 7 節の表の下の「外側のトランザクションの中で呼ばれた書き込みの口」。
 * 外側の無いときの試行の回数は RecordWriteRetryTest)。
 */
final class DeleteRecordUncheckedAuthorizationTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;

    /** 業務モジュールの判定(認可の表)と行の判定(作成者・関係する人の印の条件)の SQL */
    private const AUTHORIZATION_SQL = '/authz_|created_by_user_id|grants_row_access/';

    /** 聞き手を登録したか(登録した聞き手はテストの終わりまで残るので 1 回だけ) */
    private bool $listening = false;

    /** SQL を記録している間だけ真 */
    private bool $recording = false;

    /** @var list<string> */
    private array $recordedSqlLog = [];

    public function test_the_entry_takes_only_the_record_id(): void
    {
        $parameters = (new ReflectionMethod(DeleteRecordUnchecked::class, 'execute'))->getParameters();

        $this->assertSame(
            [['recordId', 'int']],
            array_map(fn (ReflectionParameter $parameter) => [
                $parameter->getName(),
                $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : null,
            ], $parameters),
        );
    }

    /**
     * 判定する削除は断る(403・404。そのとき認可・行の判定の SQL が出る = 記録の仕方の確かめ)が、判定しない削除は消し、その SQL が出ない。
     *
     * @param  class-string<\Throwable>  $denial
     */
    #[DataProvider('deniedContexts')]
    public function test_deletes_what_the_checked_delete_denies_without_deciding(string $case, string $denial): void
    {
        $fixture = $case === 'no-delete'
            ? $this->rowAccessFixture([RecordModuleAction::Read, RecordModuleAction::Create])
            : $this->rowAccessFixture();
        $recordId = $fixture['recordId'];
        $context = $this->contextFor($fixture['people'][$case === 'no-delete' ? 'creator' : 'stranger']->id);

        $checkedDeletionSql = $this->recordedSql(fn () => $this->assertThrows(
            fn () => app(DeleteRecord::class)->execute($context, 'meetings', $recordId),
            $denial,
        ));
        $uncheckedDeletionSql = $this->recordedSql(fn () => app(DeleteRecordUnchecked::class)->execute($recordId));

        $this->assertNotSame([], preg_grep(self::AUTHORIZATION_SQL, $checkedDeletionSql));
        $this->assertSame([], preg_grep(self::AUTHORIZATION_SQL, $uncheckedDeletionSql));
        $this->assertFalse(Record::query()->whereKey($recordId)->exists());
    }

    /**
     * @return array<string, array{string, class-string<\Throwable>}>
     */
    public static function deniedContexts(): array
    {
        return [
            '業務モジュールの delete を持たない' => ['no-delete', AuthorizationException::class],
            '行の判定で見えない(関係する人でない)' => ['hidden-row', ModelNotFoundException::class],
        ];
    }

    /**
     * 入れ子の中(テストのトランザクションが外側になる。writeRecords はいつも入れ子)では、デッドロックは 1 回で伝わる。
     * 本体のモックは、デッドロックを起こす役と呼ばれた回数を見る役。外側が無いときの試行の回数は RecordWriteRetryTest。
     */
    public function test_a_deadlock_inside_the_outer_transaction_is_passed_on_without_retrying(): void
    {
        $record = Record::factory()->create(['record_module_id' => $this->recordModule('applications')->id]);
        $this->mock(LockedRecordDeletion::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('delete')->once()->andThrow(self::deadlock()));

        $this->assertThrows(fn () => app(DeleteRecordUnchecked::class)->execute($record->id), DeadlockException::class);
        $this->assertModelExists($record);
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

    /**
     * 処理の間に出た SQL(小文字)。
     *
     * @return list<string>
     */
    private function recordedSql(Closure $action): array
    {
        if (! $this->listening) {
            DB::listen(function (QueryExecuted $query): void {
                if ($this->recording) {
                    $this->recordedSqlLog[] = strtolower($query->sql);
                }
            });
            $this->listening = true;
        }

        $this->recordedSqlLog = [];
        $this->recording = true;

        try {
            $action();
        } finally {
            $this->recording = false;
        }

        return $this->recordedSqlLog;
    }
}
