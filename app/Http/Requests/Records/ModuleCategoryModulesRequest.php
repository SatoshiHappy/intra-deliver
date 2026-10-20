<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\ModuleCategoryQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 分類の中の業務モジュールを揃える入力の封筒(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.3・4.4 の「業務モジュールの追加」の Dialog):
 * { codes: [<業務モジュールのコード>, …] }。入口が見るのは入れ物の形だけ(codes が list = 並び替えと同じ形)。中身(要素が文字列・重なり・無いコード・
 * 明細のモジュール・非表示の業務モジュール)は業務処理が確かめて、キー codes の 422 で拒む(業務処理.md 3.10)。
 */
class ModuleCategoryModulesRequest extends FormRequest
{
    use FindsRouteCategory;

    /**
     * ルートの引数の分類が無ければ、検証より前に 404(業務処理.md 3.1 = 入力の検証の 422 より先に)。
     * 管理の口はルートの認可だけで絞るので、ここで判定はしない(認可とメニュー/README.md 4 節)。
     */
    public function authorize(ModuleCategoryQuery $categories): bool
    {
        $this->routeCategory($categories);

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'codes' => ['present', 'bail', 'array', 'list'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codes.present' => '業務モジュールの一覧の形が正しくありません',
            'codes.array' => '業務モジュールの一覧の形が正しくありません',
            'codes.list' => '業務モジュールの一覧の形が正しくありません',
        ];
    }

    /**
     * 業務処理の入力(その分類に置く業務モジュールのコード。Dialog の一覧の順)。
     *
     * @return array<array-key, mixed>
     */
    public function codes(): array
    {
        $codes = $this->input('codes');

        return is_array($codes) ? $codes : [];
    }
}
