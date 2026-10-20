<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\ModuleCategoryQuery;

/**
 * モジュール分類のルートの引数 {category} を、入力の検証より前に引く FormRequest の段(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.1 =
 * 404 を 422 より先に)。管理の口はルートの認可だけで絞るので、ここで判定はしない(認可とメニュー/README.md 4 節)。
 */
trait FindsRouteCategory
{
    /**
     * ルートの分類 ID(分類の引数が無いルート = 作成・直下の並べ替えは null)。分類が無ければ読み取りが投げる例外で 404。
     */
    private function routeCategory(ModuleCategoryQuery $query): ?string
    {
        $category = $this->route('category');

        if (! is_string($category)) {
            return null;
        }

        $query->find($category);

        return $category;
    }
}
