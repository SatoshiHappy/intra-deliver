<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordAdminQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 業務モジュールの分類を変える入力の封筒(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.3・4.4 のドラッグ、業務処理.md 3.11):
 * { categories: [<分類 ID>, …] }。入口が見るのは入れ物の形だけ(categories が list)。要素の型・重なり・無い分類 ID・明細のモジュール・
 * 非表示の業務モジュールは業務モジュールを変える業務処理が確かめて、キー categories の 422 で拒む(3.7 の文言のまま)。
 */
class RecordModuleCategoriesRequest extends FormRequest
{
    /**
     * ルートの引数が指す業務モジュール(明細のモジュールを含む)が無ければ、検証より前に 404(業務モジュールの更新の FormRequest と同じ)。
     * 管理の口はルートの認可だけで絞るので、ここで判定はしない(認可とメニュー/README.md 4 節)。
     */
    public function authorize(RecordAdminQuery $admin): bool
    {
        $admin->findTargets((string) $this->route('module'));

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'categories' => ['present', 'bail', 'array', 'list'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categories.present' => 'モジュール分類の一覧の形が正しくありません',
            'categories.array' => 'モジュール分類の一覧の形が正しくありません',
            'categories.list' => 'モジュール分類の一覧の形が正しくありません',
        ];
    }

    /**
     * 業務処理の入力(categories だけ。入力に無いキーは業務処理が今の値のままにする = 業務処理.md 3.11)。
     *
     * @return array{categories: array<array-key, mixed>}
     */
    public function moduleInput(): array
    {
        $categories = $this->input('categories');

        return ['categories' => is_array($categories) ? $categories : []];
    }
}
