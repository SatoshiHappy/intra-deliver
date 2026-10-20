<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\ModuleCategoryQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * モジュール分類を作る・名前を変える入力の封筒(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.3・4.4): { name }。1 つの値なので、
 * 入口で見る形は無い(規則なし)。名前の中身(空・長さ・U+0000)と分類の数の上限は業務処理が確かめて、キー name・category の 422 で拒む(業務処理.md 3.2・3.3)。
 */
class ModuleCategoryInputRequest extends FormRequest
{
    use FindsRouteCategory;

    /** 業務処理に渡す入力の名前 */
    private const KEYS = ['name'];

    /**
     * ルートの引数の分類(名前を変えるとき)が無ければ、検証より前に 404(業務処理.md 3.1 = 入力の検証の 422 より先に)。
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
        return [];
    }

    /**
     * 業務処理の入力(送られたキーだけ)。
     *
     * @return array<string, mixed>
     */
    public function categoryInput(): array
    {
        return $this->only(self::KEYS);
    }
}
