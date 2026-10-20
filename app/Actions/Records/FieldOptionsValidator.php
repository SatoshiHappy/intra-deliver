<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use Illuminate\Validation\ValidationException;

/**
 * 項目の定義の入力のうち選択肢を確かめる(docs の 設計/業務データの入れ物/項目と規則/README.md 4 節、誤りの段 =
 * 項目と規則/規則と業務処理/失敗と定義の変更.md 11 節の「選択肢」の段)。項目の定義の確かめ {@see FieldDefinitionValidator} が呼ぶ。
 */
class FieldOptionsValidator
{
    use ValidatesDefinitionText;

    /**
     * 選択肢の入力(選択の項目だけ。配列の全体。変えるときに入力に無ければ今の選択肢)。前の段の誤り $errors があれば、選択肢の誤りと一緒に投げる。
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<string, string|null>  $errors  前の段の誤り
     * @return list<array{code: string, name: string}>
     *
     * @throws ValidationException 規則に反するとき
     */
    public function validate(RecordFieldType $type, ?RecordField $current, array $input, array $errors): array
    {
        $given = $input['options'] ?? null;

        if ($type !== RecordFieldType::Choice) {
            $misplaced = $given === null || $given === [] ? null : '選択肢を設定できるのは選択の項目だけです';
            $this->throwIfAny([...$errors, 'options' => $misplaced]);

            return [];
        }

        $currentOptions = $current?->recordFieldOptions
            ->map(fn (RecordFieldOption $option) => $option->only(['code', 'name']))
            ->all();
        $options = array_key_exists('options', $input) ? $given : $currentOptions;
        $options = is_array($options) ? array_values($options) : [];
        $this->throwIfAny([...$errors, 'options' => $options === [] ? '選択肢を 1 つ以上入力してください' : null]);

        return $this->optionList($options, $errors);
    }

    /**
     * @param  list<mixed>  $options
     * @param  array<string, string|null>  $errors
     * @return list<array{code: string, name: string}>
     *
     * @throws ValidationException 規則に反するとき
     */
    private function optionList(array $options, array $errors): array
    {
        $codes = [];

        foreach ($options as $index => $option) {
            $code = is_array($option) ? ($option['code'] ?? null) : null;
            $repeated = in_array($code, $codes, true) ? '同じコードが 2 度あります' : null;
            $errors["options.{$index}.code"] = $this->optionCodeError($code) ?? $repeated;
            $name = is_array($option) ? ($option['name'] ?? null) : null;
            $errors["options.{$index}.name"] = $this->textError($name, '名称');
            $codes[] = $code;
        }

        $this->throwIfAny($errors);

        return array_map(
            fn (mixed $option) => ['code' => (string) $option['code'], 'name' => (string) $option['name']],
            $options,
        );
    }
}
