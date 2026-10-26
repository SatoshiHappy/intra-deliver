<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\Api\UpdateRecordUnchecked;
use App\Actions\Records\LockedRecordUpdate;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordRevision;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery\MockInterface;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 判定しない更新の公開の口(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2 の「判定しない更新」、保証 =
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表の行と、表の下の「外側のトランザクションの中で呼ばれた書き込みの口」)。
 * 判定する更新が断る利用者の文脈でも書き、更新者と版の操作した人がその利用者になる。持ち主・検証・404 は判定する更新と同じで、
 * 受けない入力は作りの誤り。参照制限の 2 つのキーは今の {@see RecordInput} に無い(渡せない。キーの一覧を固定する型の制約のテスト =
 * Tests\Unit\Actions\Records\RecordInputKeysTest)。
 */
final class UpdateRecordUncheckedTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    private const OPERATION = 'AI(議事録の作成)';

    /**
     * 判定する更新は断る(403・404)が、判定しない更新は書き、更新者・版の操作した人・版の契機が入る。
     *
     * @param  'no-update'|'hidden-row'|'inactive'  $case
     * @param  class-string<\Throwable>  $denial
     */
    #[DataProvider('deniedContexts')]
    public function test_writes_what_the_checked_update_denies_as_the_given_user(string $case, string $denial): void
    {
        $fixture = $case === 'no-update'
            ? $this->rowAccessFixture([RecordModuleAction::Read, RecordModuleAction::Create])
            : $this->rowAccessFixture();
        $user = match ($case) {
            'no-update' => $fixture['people']['creator'],
            'hidden-row' => $fixture['people']['stranger'],
            'inactive' => $this->user([['2020-01-01', null, true]]),
        };
        $recordId = $fixture['recordId'];
        $context = $this->contextFor($user->id);

        $this->assertThrows(
            fn () => app(UpdateRecord::class)->execute($context, 'meetings', $recordId, $this->titled('拒まれる'), null),
            $denial,
        );
        app(UpdateRecordUnchecked::class)
            ->execute($context, 'meetings', $recordId, $this->titled('議事録'), $this->origin());

        $record = Record::query()->findOrFail($recordId);
        $this->assertSame('議事録', $this->titleOf($record));
        $this->assertSame($user->id, $record->updated_by_user_id);
        $revision = RecordRevision::query()->where('record_id', $recordId)->orderByDesc('revision')->firstOrFail();
        $this->assertSame([RecordRevisionAction::Update, $user->id, null, self::OPERATION, null], [
            $revision->action,
            $revision->user_id,
            $revision->principal_user_id,
            $revision->origin_operation,
            $revision->origin_node_name,
        ]);
    }

    /**
     * @return array<string, array{'no-update'|'hidden-row'|'inactive', class-string<\Throwable>}>
     */
    public static function deniedContexts(): array
    {
        return [
            '業務モジュールの update を持たない' => ['no-update', AuthorizationException::class],
            '行の判定で見えない(関係する人でない)' => ['hidden-row', ModelNotFoundException::class],
            'その日に有効でない' => ['inactive', ModelNotFoundException::class],
        ];
    }

    /**
     * 関係する人の印の無いユーザーの項目は受ける(断るのは他モジュール参照と印の付いた項目だけ)。
     */
    public function test_accepts_a_user_field_without_the_related_flag(): void
    {
        $fixture = $this->rowAccessFixture();
        $watcher = $fixture['people']['stranger'];

        $this->updateUnchecked($fixture['people']['creator']->id, $fixture['recordId'], ['watcher' => $watcher->id]);

        $field = RecordField::query()->where('record_module_id', $fixture['module']->id)->where('code', 'watcher')
            ->firstOrFail();
        $record = Record::query()->with('recordReferences')->findOrFail($fixture['recordId']);
        $this->assertSame($watcher->id, $record->referenceValue($field));
    }

    public function test_a_held_record_is_rejected_with_the_hold_reason(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->registerRecordHolds([$fixture['recordId'] => 'workflow-case:1']);

        $errors = $this->validationErrors(
            fn () => $this->updateUnchecked($fixture['people']['creator']->id, $fixture['recordId'], ['title' => '議']),
        );

        $this->assertSame(['record' => '申請済みのため変更できません'], $errors);
        $this->assertSame('定例', $this->titleOf(Record::query()->findOrFail($fixture['recordId'])));
    }

    public function test_a_value_the_container_rejects_is_a_validation_error(): void
    {
        $fixture = $this->rowAccessFixture();
        RecordField::query()->where('record_module_id', $fixture['module']->id)->where('code', 'title')
            ->update(['is_required' => true]);

        $errors = $this->validationErrors(
            fn () => $this->updateUnchecked($fixture['people']['creator']->id, $fixture['recordId'], ['title' => '']),
        );

        $this->assertSame(['values.title' => 'titleを入力してください'], $errors);
    }

    /**
     * 業務モジュールが無い・明細のモジュール、レコードが無い・別の業務モジュール・明細の行は 404。
     */
    public function test_a_missing_module_or_record_is_not_found(): void
    {
        $fixture = $this->rowAccessFixture();
        $row = Record::query()->where('parent_record_id', $fixture['recordId'])->firstOrFail();
        $foreign = Record::factory()->create(['record_module_id' => $this->recordModule('projects')->id]);
        $calls = [
            ['nope', $fixture['recordId']],
            ['rows_rows', $row->id],
            ['meetings', 999_999_999],
            ['meetings', $foreign->id],
            ['meetings', $row->id],
        ];

        foreach ($calls as [$moduleCode, $recordId]) {
            $this->assertThrows(fn () => app(UpdateRecordUnchecked::class)->execute(
                $this->contextFor($fixture['people']['creator']->id),
                $moduleCode,
                $recordId,
                $this->titled('議事録'),
                $this->origin(),
            ), ModelNotFoundException::class);
        }
    }

    /**
     * 受けない入力は作りの誤りで、同じ入力の項目も書かない。
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, list<RecordDetailRowInput>>  $details
     */
    #[DataProvider('unacceptedInputs')]
    public function test_an_unaccepted_input_is_a_programming_error(
        array $values,
        array $details,
        string $message,
    ): void {
        $fixture = $this->rowAccessFixture();
        $this->referenceField($fixture['module'], 'project', $this->recordModule('projects'));
        $input = new RecordInput(['title' => '議事録', ...$values], $details);

        $this->assertThrows(
            fn () => app(UpdateRecordUnchecked::class)->execute(
                $this->contextFor($fixture['people']['creator']->id),
                'meetings',
                $fixture['recordId'],
                $input,
                $this->origin(),
            ),
            LogicException::class,
            $message,
        );
        $this->assertSame('定例', $this->titleOf(Record::query()->findOrFail($fixture['recordId'])));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, list<RecordDetailRowInput>>, string}>
     */
    public static function unacceptedInputs(): array
    {
        return [
            '他モジュール参照の項目' => [['project' => null], [], '他モジュール参照の項目を受けない: project'],
            '関係する人の印の付いたユーザーの項目' => [['attendee' => null], [], '関係する人の印の付いた項目を受けない: attendee'],
            '関係する人の印の付いた組織(複数)の項目' => [['sections' => []], [], '関係する人の印の付いた項目を受けない: sections'],
            '明細の入力(行を消す)' => [[], ['rows' => []], '明細の入力を受けない: rows'],
            '明細の入力(行を足す)' => [
                [],
                ['rows' => [new RecordDetailRowInput(values: ['assignee' => null])]],
                '明細の入力を受けない: rows',
            ],
        ];
    }

    /**
     * 外側のトランザクションの中では savepoint になる: 口の例外は伝わって外側ごと巻き戻り、書いた後に外側が巻き戻れば書いた値も残らない。
     */
    public function test_inside_an_outer_transaction_the_update_is_part_of_it(): void
    {
        $fixture = $this->rowAccessFixture();
        $userId = $fixture['people']['creator']->id;
        $recordId = $fixture['recordId'];
        $revisions = RecordRevision::query()->where('record_id', $recordId)->count();

        $this->assertThrows(fn () => DB::transaction(function () use ($userId, $recordId): void {
            $this->updateUnchecked($userId, $recordId, ['title' => '議事録']);

            throw new RuntimeException('外側の失敗');
        }), RuntimeException::class, '外側の失敗');

        $this->assertSame('定例', $this->titleOf(Record::query()->findOrFail($recordId)));
        $this->assertSame($revisions, RecordRevision::query()->where('record_id', $recordId)->count());

        $this->registerRecordHolds([$recordId => 'workflow-case:1']);
        $written = null;
        $errors = $this->validationErrors(function () use ($fixture, $userId, $recordId, &$written): void {
            DB::transaction(function () use ($fixture, $userId, $recordId, &$written): void {
                $written = Record::factory()->create(['record_module_id' => $fixture['module']->id]);
                $this->updateUnchecked($userId, $recordId, ['title' => '議事録']);
            });
        });

        $this->assertSame(['record' => '申請済みのため変更できません'], $errors);
        $this->assertNotNull($written);
        $this->assertModelMissing($written);
    }

    /**
     * 入れ子の中(テストのトランザクションが外側になる)では、デッドロックは口でやり直さずに 1 回で伝わる。
     */
    public function test_a_deadlock_inside_the_outer_transaction_is_passed_on_without_retrying(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->mock(LockedRecordUpdate::class, function (MockInterface $mock): void {
            $mock->shouldReceive('throwIfHeld')->once();
            $mock->shouldReceive('write')->once()->andThrow(self::deadlock());
        });

        $this->assertThrows(
            fn () => $this->updateUnchecked($fixture['people']['creator']->id, $fixture['recordId'], ['title' => '議']),
            DeadlockException::class,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateUnchecked(int $userId, int $recordId, array $values): void
    {
        app(UpdateRecordUnchecked::class)->execute(
            $this->contextFor($userId),
            'meetings',
            $recordId,
            new RecordInput($values),
            $this->origin(),
        );
    }

    private function titled(string $title): RecordInput
    {
        return new RecordInput(['title' => $title]);
    }

    private function origin(): RecordRevisionOrigin
    {
        return new RecordRevisionOrigin(self::OPERATION);
    }

    private function titleOf(Record $record): mixed
    {
        $title = RecordField::query()->where('record_module_id', $record->record_module_id)->where('code', 'title')
            ->firstOrFail();

        return $record->field_values[$title->id] ?? null;
    }

    /**
     * PostgreSQL のデッドロック(SQLSTATE 40P01)の QueryException。
     */
    private static function deadlock(): QueryException
    {
        $pdo = new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '40P01');

        return new QueryException('pgsql', 'update "records" set "field_values" = ? where "id" = ?', [], $pdo);
    }
}
