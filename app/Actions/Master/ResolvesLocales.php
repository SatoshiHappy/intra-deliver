<?php

namespace App\Actions\Master;

use App\Models\Master\Locale;
use Illuminate\Validation\ValidationException;

/**
 * ロケール別の値の入力(ロケールのコード → 値)のコードを、locales の id に引き直す。
 */
trait ResolvesLocales
{
    /**
     * @param  array<string, mixed>  $valuesByLocaleCode
     * @return array<string, int> ロケールのコード → id
     *
     * @throws ValidationException 無いロケールのコードがあるとき
     */
    private function localeIds(array $valuesByLocaleCode): array
    {
        $codes = array_keys($valuesByLocaleCode);
        $ids = Locale::query()->whereIn('code', $codes)->pluck('id', 'code')->all();
        $unknown = array_diff($codes, array_keys($ids));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['locale' => ['無いロケールです: '.implode(', ', $unknown)]]);
        }

        return $ids;
    }
}
