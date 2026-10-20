<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 複数選べる参照の値が呼び手に依らないこと(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節の「呼び手に依らない」と
 * 「複数選べる参照」、失敗と定義の変更.md 10 節のキーの形): 同じ入力を HTTP の要求(画面と同じ JSON の本文)と業務処理の直接の呼び出しで与えると、
 * 書く行と検証の結果(キーと文言)が同じ。
 */
final class MultipleReferenceCallerIndependenceTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    private const MODULE = 'caller_check';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $module = $this->recordModule(self::MODULE);
        $this->field($module, 'members', RecordFieldType::User, ['is_multiple' => true]);
    }

    public function test_the_http_request_and_the_direct_call_store_the_same_rows(): void
    {
        $values = ['members' => [$this->user()->id, $this->user()->id]];

        $this->postJson('/records/data/'.self::MODULE, ['values' => $values])->assertStatus(303);
        $viaHttp = Record::query()->latest('id')->firstOrFail();
        $direct = app(CreateRecord::class)->execute($this->contextFor(), self::MODULE, new RecordInput($values));

        $this->assertSame($values['members'], $this->targetsOf($viaHttp->id));
        $this->assertSame($values['members'], $this->targetsOf($direct));
    }

    public function test_the_http_request_and_the_direct_call_return_the_same_errors(): void
    {
        $user = $this->user();
        $retired = $this->user([['2020-01-01', '2026-04-01']]);
        $count = Record::query()->count();
        $cases = [
            '同じ相手が 2 度' => [[$user->id, $user->id], 'values.members'],
            '有効でない相手' => [[$user->id, $retired->id], 'values.members.1'],
            '配列でない' => ['x', 'values.members'],
            '整数でない要素' => [[$user->id, '2'], 'values.members'],
        ];

        foreach ($cases as $label => [$members, $key]) {
            $values = ['members' => $members];
            $viaHttp = $this->postJson('/records/data/'.self::MODULE, ['values' => $values])
                ->assertUnprocessable()
                ->json('errors');
            $direct = $this->directErrors($values);

            $this->assertSame($direct, $viaHttp, $label);
            $this->assertSame([$key], array_keys($direct), $label);
        }

        $this->assertSame($count, Record::query()->count());
    }

    /**
     * レコードの参照の行の相手(sort_key の順)。
     *
     * @return list<int|null>
     */
    private function targetsOf(int $recordId): array
    {
        $rows = RecordReference::query()->where('record_id', $recordId)->orderBy('sort_key');

        return array_values($rows->pluck('user_id')->all());
    }

    /**
     * 業務処理を直接呼んだときの検証の失敗(キー → 文言のすべて)。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array<int, string>>
     */
    private function directErrors(array $values): array
    {
        try {
            app(CreateRecord::class)->execute($this->contextFor(), self::MODULE, new RecordInput($values));
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('検証の失敗にならなかった');
    }
}
