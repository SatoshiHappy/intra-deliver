<?php

namespace App\Actions\Authz;

use App\Actions\Master\ResolvesLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Models\Authz\AuthzResourceGroup;
use Illuminate\Validation\ValidationException;

/**
 * 認可のリソースグループ 1 つの表示名を置き換える(docs の 設計/メニュー/認可との結び付け.md 2.4 の「リソースグループの表示名を変える」)。
 * 入力 = ロケールのコード → 表示名。入力にあるロケールは、行があれば表示名を更新し(説明は変えない)、無ければ作る(説明は NULL)。
 * 入力に無いロケールの行は変えない。説明・並び順・ID・親は変えない。呼び手(業務モジュールの名前の写し = 設計/業務データの入れ物/認可とメニュー/README.md 1 節)に
 * 要る分だけの狭い規則で、認可設定画面を作るときに広げる。
 */
class RenameResourceGroup
{
    use ResolvesLocales;
    use WritesCommonMaster;

    /**
     * @param  string  $code  リソースグループ ID
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名
     *
     * @throws ValidationException リソースグループが無いとき、無いロケールのコードがあるとき
     */
    public function execute(string $code, array $displayNames): AuthzResourceGroup
    {
        return $this->writeCommonMaster(function () use ($code, $displayNames) {
            $group = AuthzResourceGroup::query()->where('code', $code)->first()
                ?? throw ValidationException::withMessages(['resource_group' => ["認可のリソースグループがありません: {$code}"]]);
            $localeIds = $this->localeIds($displayNames);

            foreach ($displayNames as $localeCode => $displayName) {
                $group->authzResourceGroupTranslations()->updateOrCreate(['locale_id' => $localeIds[$localeCode]], ['display_name' => $displayName]);
            }

            return $group;
        });
    }
}
