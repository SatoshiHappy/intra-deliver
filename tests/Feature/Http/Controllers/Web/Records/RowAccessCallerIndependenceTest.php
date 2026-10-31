<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Models\Records\Record;
use App\Models\Records\RecordRowAccessViewer;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 参照制限の入力が呼び手に依らないこと(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節の「呼び手に依らない」、
 * README.md 9.1 の HTTP の封筒): 同じ入力を HTTP の要求(画面と同じ JSON の本文。最上位の row_access_restricted・row_access_viewers)と
 * 業務処理の直接の呼び出しで与えると、書くチェック・参照者と検証の結果(キーと文言)が同じ。封筒では row_access_viewers の null は空、
 * キーが無いのと row_access_restricted の null は未指定。
 */
final class RowAccessCallerIndependenceTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    private const MODULE = 'caller_check';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->recordModule(self::MODULE)->update(['row_access_restricted_by_default' => true]);
    }

    public function test_the_http_request_and_the_direct_call_store_the_same_check_and_viewers(): void
    {
        $viewers = [['type' => 'user', 'id' => $this->user()->id], ['type' => 'user', 'id' => $this->user()->id]];
        $cases = [
            '参照者' => [['row_access_restricted' => true, 'row_access_viewers' => $viewers], [true, $viewers]],
            'キーなし(既定)' => [[], [true, []]],
            'チェックの null(既定)' => [['row_access_restricted' => null], [true, []]],
            '参照者の null(空)' => [['row_access_restricted' => true, 'row_access_viewers' => null], [true, []]],
            'チェックを外す' => [['row_access_restricted' => false], [false, []]],
        ];

        foreach ($cases as $label => [$body, $expected]) {
            $this->postJson('/records/data/'.self::MODULE, ['values' => [], ...$body])->assertStatus(303);
            $viaHttp = Record::query()->latest('id')->firstOrFail();
            $direct = app(CreateRecord::class)->execute($this->contextFor(), self::MODULE, new RecordInput(
                rowAccessRestricted: $body['row_access_restricted'] ?? null,
                rowAccessViewers: array_key_exists('row_access_viewers', $body)
                    ? ($body['row_access_viewers'] ?? [])
                    : null,
            ));

            $this->assertSame($expected, $this->rowAccessOf($viaHttp->id), $label);
            $this->assertSame($expected, $this->rowAccessOf($direct), $label);
        }
    }

    public function test_the_http_request_and_the_direct_call_return_the_same_errors(): void
    {
        $user = $this->user();
        $retired = $this->user([['2020-01-01', '2026-04-01']]);
        $count = Record::query()->count();
        $one = $this->party($user->id);
        $cases = [
            '真偽でないチェック' => [['row_access_restricted' => 'yes'], ['row_access_restricted']],
            '配列でない参照者' => [['row_access_viewers' => 'x'], ['row_access_viewers']],
            '相手の形でない要素' => [
                ['row_access_viewers' => [['type' => 'user', 'id' => (string) $user->id]]],
                ['row_access_viewers.0'],
            ],
            '同じ相手が 2 度' => [['row_access_viewers' => [$one, $one]], ['row_access_viewers']],
            '有効でない相手' => [['row_access_viewers' => [$one, $this->party($retired->id)]], ['row_access_viewers.1']],
            'チェックを外して参照者' => [
                ['row_access_restricted' => false, 'row_access_viewers' => [$one]],
                ['row_access_viewers'],
            ],
        ];

        foreach ($cases as $label => [$body, $keys]) {
            $viaHttp = $this->postJson('/records/data/'.self::MODULE, ['values' => [], ...$body])
                ->assertUnprocessable()
                ->json('errors');
            $direct = $this->directErrors($body);

            $this->assertSame($direct, $viaHttp, $label);
            $this->assertSame($keys, array_keys($direct), $label);
        }

        $this->assertSame($count, Record::query()->count());
    }

    /**
     * @return array{type: string, id: int}
     */
    private function party(int $userId): array
    {
        return ['type' => 'user', 'id' => $userId];
    }

    /**
     * @return array{bool, list<array{type: 'user'|'department', id: int}>}
     */
    private function rowAccessOf(int $recordId): array
    {
        $record = Record::query()->findOrFail($recordId);

        $viewers = $record->rowAccessViewers->map(fn (RecordRowAccessViewer $viewer) => $viewer->party());

        return [$record->row_access_restricted, array_values($viewers->all())];
    }

    /**
     * 業務処理を直接呼んだときの検証の失敗(キー → 文言のすべて)。
     *
     * @param  array<string, mixed>  $body
     * @return array<string, array<int, string>>
     */
    private function directErrors(array $body): array
    {
        $input = new RecordInput(
            rowAccessRestricted: $body['row_access_restricted'] ?? null,
            rowAccessViewers: $body['row_access_viewers'] ?? null,
        );

        try {
            app(CreateRecord::class)->execute($this->contextFor(), self::MODULE, $input);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('検証の失敗にならなかった');
    }
}
