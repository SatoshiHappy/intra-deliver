<?php

namespace App\Actions\Master;

use App\Models\Master\Locale;

/**
 * すべてのロケールに同じ値を持たせる入力(ロケールのコードの順 → 値)を作る。業務モジュールとモジュール分類の名前を、メニューの表示名・認可のリソース
 * グループの表示名に写すときに使う(docs の
 * 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1「業務モジュールの名前(`locales` のすべてのロケール)」、モジュール分類/README.md 1.3)。
 */
trait FillsAllLocales
{
    /**
     * @return array<string, string> ロケールのコード → 値(コードの順)
     */
    private function forAllLocales(string $value): array
    {
        return array_fill_keys(Locale::query()->orderBy('code')->pluck('code')->all(), $value);
    }
}
