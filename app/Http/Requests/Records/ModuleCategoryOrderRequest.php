<?php

namespace App\Http\Requests\Records;

use Illuminate\Foundation\Http\FormRequest;

/**
 * モジュール分類の並び替えの入力の封筒(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.3・4.4): { ids: [<分類 ID>, …] }。
 * 入口が見るのは入れ物の形だけ(ids が list)。中身(すべての分類 ID の順列か)は業務処理が確かめて、キー ids の 422 で拒む(業務処理.md 3.5)。
 */
class ModuleCategoryOrderRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['present', 'bail', 'array', 'list'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.present' => 'モジュール分類の並びの形が正しくありません',
            'ids.array' => 'モジュール分類の並びの形が正しくありません',
            'ids.list' => 'モジュール分類の並びの形が正しくありません',
        ];
    }

    /**
     * 業務処理の入力(分類 ID の新しい順)。
     *
     * @return array<array-key, mixed>
     */
    public function ids(): array
    {
        $ids = $this->input('ids');

        return is_array($ids) ? $ids : [];
    }
}
