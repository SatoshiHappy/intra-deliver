<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RelativeDefault;
use App\Queries\Records\FieldValueParser;
use App\Queries\Records\InvalidFieldValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 項目の定義の既定値を確かめて正規化する(docs の 設計/業務データの入れ物/項目と規則/README.md 3 節、
 * 規則と業務処理/失敗と定義の変更.md 11 節)。固定の値は、変えた後の属性と選択肢で 2 節の検証に掛ける。
 * 日付・日時は相対の既定値のオブジェクトも受け、形は {@see RelativeDefault::tryFromObject()} で確かめる。
 */
class FieldDefaultValidator
{
    /** 相対の既定値を持てる型 */
    private const RELATIVE_TYPES = [RecordFieldType::Date, RecordFieldType::Datetime];

    public function __construct(private FieldValueParser $parser) {}

    /**
     * @param  array<string, mixed>  $attributes  形を確かめた属性(default_value は入力か今の値)
     * @param  list<array{code: string, name: string}>  $options
     *
     * @throws ValidationException 型・文字数・選択肢・相対の既定値の形に合わないとき(キー default_value)
     */
    public function validate(
        RecordFieldType $type,
        array $attributes,
        array $options,
    ): string|int|bool|RelativeDefault|null {
        $value = $attributes['default_value'];

        $isRelativeType = in_array($type, self::RELATIVE_TYPES, true);

        // 日付・日時の値の型は形を確かめて作ったもの。ほかの型に来たら(業務処理の直接の呼び出し)配列と同じく 2 節の検証が型の誤りにする
        if ($value === null || ($value instanceof RelativeDefault && $isRelativeType)) {
            return $value;
        }

        if (is_array($value) && $isRelativeType) {
            return $this->relative($value);
        }

        $probe = new RecordField([
            'name' => '既定値',
            'type' => $type,
            'is_multiline' => $attributes['is_multiline'],
            'max_length' => $attributes['max_length'],
        ]);
        $probe->setRelation(
            'recordFieldOptions',
            new Collection(array_map(fn (array $option) => new RecordFieldOption($option), $options)),
        );

        try {
            return $this->parser->parse($probe, $value);
        } catch (InvalidFieldValue $exception) {
            throw ValidationException::withMessages(['default_value' => [$exception->getMessage()]]);
        }
    }

    /**
     * 相対の既定値の入力(保存形のオブジェクト)。
     *
     * @param  array<array-key, mixed>  $value
     *
     * @throws ValidationException 形が違うとき
     */
    private function relative(array $value): RelativeDefault
    {
        return RelativeDefault::tryFromObject($value) ?? throw ValidationException::withMessages([
            'default_value' => ['登録の時からの期間は 0〜999 の日・か月・年で指定してください'],
        ]);
    }
}
