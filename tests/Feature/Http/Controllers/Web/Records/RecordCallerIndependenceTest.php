<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの業務処理が呼び手に依らないこと(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節の「呼び手に依らない」のレコードの分、
 * 項目と規則/README.md 2 節の前文・3 節、規則と業務処理/README.md 9.1、失敗と定義の変更.md 10 節):
 * 同じ入力を HTTP の要求(records.data.store。画面と同じ JSON の本文)と業務処理の直接の呼び出しで与えると、既定値と
 * 検証の結果(キーと文言)が同じ。前後に空白を持つ値と空白だけの値を、文字列・整数・選択の値で含める(入口の TrimStrings・
 * ConvertEmptyStringsToNull と、業務処理の同じ変換)。初期データ(ルートのリソースとポリシー)を入れ、業務モジュールはテストで作る。
 */
final class RecordCallerIndependenceTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    private const MODULE = 'caller_check';

    private RecordModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->module = $this->recordModule(self::MODULE);
        $this->field($this->module, 'title', attributes: ['is_required' => true, 'default_value' => 'なし']);
        $this->field($this->module, 'amount', RecordFieldType::Integer, ['default_value' => 3]);
        $this->choiceField($this->module, 'kind', ['a', 'b'], ['is_required' => true, 'default_value' => 'a']);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function acceptedInputs(): array
    {
        return [
            'キーの無い項目に既定値' => [[], ['title' => 'なし', 'amount' => 3, 'kind' => 'a']],
            '前後の空白を除く' => [['title' => '  件名  ', 'amount' => ' 12 ', 'kind' => ' b '], ['title' => '件名', 'amount' => 12, 'kind' => 'b']],
            '空白だけの値は空(既定値を入れない)' => [['title' => 'x', 'amount' => '   ', 'kind' => 'b'], ['title' => 'x', 'kind' => 'b']],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $expected  項目のコード → 持つ値
     */
    #[DataProvider('acceptedInputs')]
    public function test_the_http_request_and_the_direct_call_store_the_same_values(array $values, array $expected): void
    {
        $this->postJson('/records/data/'.self::MODULE, ['values' => $values])->assertStatus(303);
        $viaHttp = $this->latestRecord();
        $direct = Record::query()->findOrFail(app(CreateRecord::class)->execute($this->contextFor(), self::MODULE, new RecordInput($values)));

        $this->assertNotSame($viaHttp->id, $direct->id);
        $this->assertEquals($viaHttp->field_values, $direct->refresh()->field_values);
        $this->assertEquals($this->keyedById($expected), $direct->field_values);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function rejectedInputs(): array
    {
        return [
            '空白だけの必須の値' => [['title' => '   ', 'amount' => '   ', 'kind' => '   '], ['values.title', 'values.kind']],
            '空白つきの型の誤りと選択肢に無い値' => [['title' => ' x ', 'amount' => ' 1.5 ', 'kind' => ' z '], ['values.amount', 'values.kind']],
            '項目に無いキー' => [['title' => 'x', 'nothing' => ' y '], ['values.nothing']],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys  失敗のキー
     */
    #[DataProvider('rejectedInputs')]
    public function test_the_http_request_and_the_direct_call_return_the_same_errors(array $values, array $keys): void
    {
        $count = Record::query()->count();

        $viaHttp = $this->postJson('/records/data/'.self::MODULE, ['values' => $values])->assertUnprocessable()->json('errors');
        $direct = $this->directErrors($values);

        $this->assertSame($direct, $viaHttp);
        $this->assertSame($keys, array_keys($direct));
        $this->assertSame($count, Record::query()->count());
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

    /**
     * 項目のコード → 値を、項目の id → 値にする。
     *
     * @param  array<string, mixed>  $values
     * @return array<int, mixed>
     */
    private function keyedById(array $values): array
    {
        $ids = $this->module->recordFields()->pluck('id', 'code');
        $keyed = [];

        foreach ($values as $code => $value) {
            $keyed[(int) $ids[$code]] = $value;
        }

        return $keyed;
    }

    private function latestRecord(): Record
    {
        return Record::query()->where('record_module_id', $this->module->id)->latest('id')->firstOrFail();
    }
}
