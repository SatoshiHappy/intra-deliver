<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Queries\Records\FieldValueParser;
use App\Queries\Records\InvalidFieldValue;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 型ごとに受け付ける値と正規化(docs の 設計/業務データの入れ物/項目と規則/README.md 2 節の表、13 節の「型ごとの検証」。
 * 値の JSON の形は 設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節)。
 * 業務処理を通した 422 とキーは tests/Feature/Actions/Records/CreateRecordTest.php。
 */
final class FieldValueParserTest extends TransactionalTestCase
{
    /**
     * @return array<string, array{0: RecordFieldType, 1: mixed, 2: string|int|bool|null, 3?: array<string, mixed>}>
     */
    public static function acceptedValues(): array
    {
        return [
            '文字列' => [RecordFieldType::String, 'abc', 'abc'],
            '複数行の文字列の改行' => [RecordFieldType::String, "a\nb", "a\nb", ['is_multiline' => true]],
            '最大の文字数ちょうど' => [RecordFieldType::String, 'あいう', 'あいう', ['max_length' => 3]],
            '整数' => [RecordFieldType::Integer, 1200, 1200],
            '整数の文字列' => [RecordFieldType::Integer, '-0012', -12],
            '整数の 15 桁' => [RecordFieldType::Integer, '999999999999999', 999_999_999_999_999],
            '小数の末尾の 0' => [RecordFieldType::Decimal, '12.50', '12.5'],
            '小数の -0' => [RecordFieldType::Decimal, '-0.000', '0'],
            '小数の先頭の 0' => [RecordFieldType::Decimal, '0001.5', '1.5'],
            '小数の小数部 4 桁(末尾の 0 を除いて数える)' => [RecordFieldType::Decimal, '1.23450', '1.2345'],
            '小数の整数部 15 桁(先頭の 0 を除いて数える)' => [RecordFieldType::Decimal, '-000999999999999999.1', '-999999999999999.1'],
            '小数に整数' => [RecordFieldType::Decimal, 7, '7'],
            '日付' => [RecordFieldType::Date, '2024-02-29', '2024-02-29'],
            '日時の時差を UTC に' => [RecordFieldType::Datetime, '2026-10-11T09:30:00+09:00', '2026-10-11T00:30:00Z'],
            '日時の秒を省く' => [RecordFieldType::Datetime, '2026-10-11T09:30+09:00', '2026-10-11T00:30:00Z'],
            '日時の Z' => [RecordFieldType::Datetime, '2026-10-11T23:59:59Z', '2026-10-11T23:59:59Z'],
            '日時の負の時差で日をまたぐ' => [RecordFieldType::Datetime, '2026-10-11T22:00-05:00', '2026-10-12T03:00:00Z'],
            'UTC で 0001 年の始まり' => [RecordFieldType::Datetime, '0001-01-01T09:00+09:00', '0001-01-01T00:00:00Z'],
            'UTC で 9999 年の終わり' => [RecordFieldType::Datetime, '9999-12-31T23:59Z', '9999-12-31T23:59:00Z'],
            '真偽' => [RecordFieldType::Boolean, true, true],
            '真偽の空は偽' => [RecordFieldType::Boolean, null, false],
            '選択' => [RecordFieldType::Choice, 'open', 'open'],
            '空は null' => [RecordFieldType::Integer, null, null],
        ];
    }

    /**
     * @return array<string, array{0: RecordFieldType, 1: mixed, 2: string, 3?: array<string, mixed>}>
     */
    public static function rejectedValues(): array
    {
        return [
            '1 行の文字列の改行' => [RecordFieldType::String, "a\r\nb", '項目は改行を含めずに入力してください'],
            '文字列の U+0000' => [RecordFieldType::String, "a\0b", '項目に使用できない文字(U+0000)が含まれています'],
            '最大の文字数を超える' => [RecordFieldType::String, 'あいうえ', '項目は 3 文字以内で入力してください', ['max_length' => 3]],
            '1 行の上限 1000 を超える' => [RecordFieldType::String, str_repeat('a', 1001), '項目は 1000 文字以内で入力してください'],
            '文字列に数' => [RecordFieldType::String, 12, '項目は文字列で入力してください'],
            '整数の 16 桁' => [RecordFieldType::Integer, '1000000000000000', '項目は 15 桁までの整数で入力してください'],
            '整数の範囲の外の数' => [RecordFieldType::Integer, -1_000_000_000_000_000, '項目は 15 桁までの整数で入力してください'],
            '整数に小数' => [RecordFieldType::Integer, '1.5', '項目は整数で入力してください'],
            '整数に浮動小数点の数' => [RecordFieldType::Integer, 1.0, '項目は整数で入力してください'],
            '小数の小数部 5 桁' => [RecordFieldType::Decimal, '1.23451', '項目は整数部 15 桁・小数部 4 桁までの数で入力してください'],
            '小数の整数部 16 桁' => [RecordFieldType::Decimal, '1000000000000000', '項目は整数部 15 桁・小数部 4 桁までの数で入力してください'],
            '小数に浮動小数点の数' => [RecordFieldType::Decimal, 1.5, '項目は数で入力してください'],
            '小数の点だけで終わる' => [RecordFieldType::Decimal, '1.', '項目は数で入力してください'],
            '実在しない日' => [RecordFieldType::Date, '2026-02-29', '項目は日付(YYYY-MM-DD)で入力してください'],
            '日付の形が違う' => [RecordFieldType::Date, '2026/10/11', '項目は日付(YYYY-MM-DD)で入力してください'],
            '日付の末尾の改行' => [RecordFieldType::Date, "2026-10-11\n", '項目は日付(YYYY-MM-DD)で入力してください'],
            '時差の無い日時' => [RecordFieldType::Datetime, '2026-10-11T09:30:00', '項目は時差つきの日時(例 2026-10-11T09:30+09:00)で入力してください'],
            '日時の秒の小数' => [RecordFieldType::Datetime, '2026-10-11T09:30:00.5+09:00', '項目は時差つきの日時(例 2026-10-11T09:30+09:00)で入力してください'],
            '日時の基本形式' => [RecordFieldType::Datetime, '20261011T093000+0900', '項目は時差つきの日時(例 2026-10-11T09:30+09:00)で入力してください'],
            '日時の短い時差' => [RecordFieldType::Datetime, '2026-10-11T09:30+09', '項目は時差つきの日時(例 2026-10-11T09:30+09:00)で入力してください'],
            '実在しない時刻' => [RecordFieldType::Datetime, '2026-10-11T24:00+09:00', '項目は時差つきの日時(例 2026-10-11T09:30+09:00)で入力してください'],
            'UTC で 0 年になる日時' => [RecordFieldType::Datetime, '0001-01-01T05:00+09:00', '項目は 0001 年から 9999 年までの日時で入力してください'],
            'UTC で 10000 年になる日時' => [RecordFieldType::Datetime, '9999-12-31T23:59-01:00', '項目は 0001 年から 9999 年までの日時で入力してください'],
            '真偽に文字列' => [RecordFieldType::Boolean, '1', '項目は真偽(true・false)で指定してください'],
            '選択肢に無いコード' => [RecordFieldType::Choice, 'closed', '項目の値が正しくありません'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('acceptedValues')]
    public function test_accepts_and_normalizes_a_value(RecordFieldType $type, mixed $value, string|int|bool|null $expected, array $attributes = []): void
    {
        $this->assertSame($expected, app(FieldValueParser::class)->parse($this->fieldOf($type, $attributes), $value));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('rejectedValues')]
    public function test_rejects_a_value_with_a_message_naming_the_field(RecordFieldType $type, mixed $value, string $message, array $attributes = []): void
    {
        $this->expectException(InvalidFieldValue::class);
        $this->expectExceptionMessage($message);

        app(FieldValueParser::class)->parse($this->fieldOf($type, $attributes), $value);
    }

    /**
     * UTC に直した後の年の範囲の外は、値の形の誤り(InvalidFieldValue の既定)。
     */
    #[TestWith(['0001-01-01T05:00+09:00'])]
    #[TestWith(['9999-12-31T23:59-01:00'])]
    public function test_a_datetime_out_of_the_year_range_in_utc_is_a_wrong_shape(string $value): void
    {
        try {
            app(FieldValueParser::class)->parse($this->fieldOf(RecordFieldType::Datetime, []), $value);
            $this->fail('拒まれなかった');
        } catch (InvalidFieldValue $error) {
            $this->assertTrue($error->isWrongShape);
        }
    }

    /**
     * 保存しない項目(名前「項目」。選択の項目は選択肢 open を持つ)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fieldOf(RecordFieldType $type, array $attributes): RecordField
    {
        $field = new RecordField(['name' => '項目', 'type' => $type, 'is_multiline' => false, 'max_length' => null, ...$attributes]);
        $field->setRelation('recordFieldOptions', new Collection([new RecordFieldOption(['code' => 'open', 'name' => '未着手'])]));

        return $field;
    }
}
