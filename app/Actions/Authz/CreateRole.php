<?php

namespace App\Actions\Authz;

use App\Actions\Master\ResolvesLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Models\Authz\Role;
use Illuminate\Validation\ValidationException;

/**
 * ロールを作る(docs の 設計/共通マスタ/ロールと認可/ロール.md 1 節)。ロール名・カテゴリ・説明はアンカーに、表示名はロケール別の行に置く。
 * コードの一意・ロール名の文字の制限は DB が止める。どのロケールの表示名を必ず置くかは多言語の設計で決めるので、ここでは求めない。
 */
class CreateRole
{
    use ResolvesLocales;
    use WritesCommonMaster;

    /**
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名
     *
     * @throws ValidationException 無いロケールのコードがあるとき
     */
    public function execute(string $code, string $name, array $displayNames, ?string $category = null, ?string $notes = null, int $sortKey = 0): Role
    {
        $localeIds = $this->localeIds($displayNames);

        return $this->writeCommonMaster(function () use ($code, $name, $displayNames, $category, $notes, $sortKey, $localeIds) {
            $role = Role::query()->create(['code' => $code, 'name' => $name, 'category' => $category, 'notes' => $notes, 'sort_key' => $sortKey]);

            foreach ($displayNames as $localeCode => $displayName) {
                $role->roleTranslations()->create(['locale_id' => $localeIds[$localeCode], 'display_name' => $displayName]);
            }

            return $role;
        });
    }
}
