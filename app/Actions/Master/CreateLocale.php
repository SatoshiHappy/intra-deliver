<?php

namespace App\Actions\Master;

use App\Models\Master\Locale;

/**
 * ロケールを作る(docs の 設計/共通マスタ/組織とユーザー.md 1 節)。コードの一意は DB が止める。
 */
class CreateLocale
{
    use WritesCommonMaster;

    public function execute(string $code, int $sortKey = 0): Locale
    {
        return $this->writeCommonMaster(fn () => Locale::query()->create(['code' => $code, 'sort_key' => $sortKey]));
    }
}
