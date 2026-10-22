<?php

namespace App\Http\Requests\Records;

use Illuminate\Validation\Validator;

/**
 * レコードの登録の入力の封筒(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.1、画面/業務の画面.md 2.2): 登録と同じ封筒に、
 * 関連レコードのダイアログの新規が送る related({ field: 入口の項目のコード, record: このレコードの id })を足す
 * (設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 4.2 節)。業務処理は related を知らない: ここで入口の項目の値が related.record と
 * 同じかを確かめ(固定の値を画面の案内だけにしない)、入口は成功の戻り先だけを変える。
 */
class RecordStoreRequest extends RecordInputRequest
{
    /** 入口の項目の値が related.record と違うときの文言(関連レコード.md の 4.2 節) */
    public const FIXED_VALUE_MESSAGE = '関連レコードとして登録するため、変更できません';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'related' => ['nullable', 'bail', 'array:field,record', 'required_array_keys:field,record'],
            'related.field' => ['string', 'regex:/^[a-z][a-z0-9_]{0,49}\z/'],
            'related.record' => ['integer:strict', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = '関連レコードの指定が正しくありません';

        return [
            ...parent::messages(),
            'related.array' => $message,
            'related.required_array_keys' => $message,
            'related.field.string' => $message,
            'related.field.regex' => $message,
            'related.record.integer' => $message,
            'related.record.min' => $message,
        ];
    }

    /**
     * related の形が正しければ、入口の項目の値(values.<入口の項目>)が related.record と同じ整数かを確かめる(違えば 422 values.<入口の項目>。
     * どちらも JSON の数で比べ、真偽値・数字の文字列は一致に数えない)。
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $related = $this->input('related');

            if ($validator->errors()->isNotEmpty() || ! is_array($related)) {
                return;
            }

            $field = (string) $related['field'];
            $value = $this->input("values.{$field}");

            if (! is_int($value) || $value !== $related['record']) {
                $validator->errors()->add("values.{$field}", self::FIXED_VALUE_MESSAGE);
            }
        }];
    }

    /**
     * 関連レコードのダイアログの新規か(成功の応答を元の画面へ戻す = 関連レコード.md の 4.2 節)。null は送らないのと同じ。
     */
    public function isRelated(): bool
    {
        return is_array($this->input('related'));
    }
}
