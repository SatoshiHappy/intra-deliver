<?php

namespace App\Actions\Authz;

use App\Actions\Master\ResolvesLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Enums\Authz\ResourceGroupAttributeKey;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use Illuminate\Validation\ValidationException;

/**
 * 認可のリソースグループを作る(リソースなし。docs の 設計/メニュー/認可との結び付け.md 2.4、設計/共通マスタ/ロールと認可/リソース.md)。
 *
 * 親があれば親の閉塞を写す(ロールと認可/リソース.md の閉塞の項: 足したリソースグループに、新しい親の閉塞を同じ操作で写す。新しい行は閉塞を持たないので、
 * 親の値をそのまま持つ。親の値は祖先の閉塞を写したものなので、祖先はたどらない)。
 * 循環は作るときには起きない(新しい行は誰の親でもない)。ID の長さ・一意・空文字と、親の実在は DB が止める。
 * ロケール別の行は渡されたロケールごとに作る(どのロケールを必ず置くかは多言語の設計で決めるので、ここでは求めない)。
 */
class CreateResourceGroup
{
    use ResolvesLocales;
    use WritesCommonMaster;

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations  ロケールのコード → 表示名・説明
     *
     * @throws ValidationException 無いロケールのコードがあるとき
     */
    public function execute(string $code, ?int $parentId, array $translations, int $sortKey = 0): AuthzResourceGroup
    {
        $localeIds = $this->localeIds($translations);

        return $this->writeCommonMaster(function () use ($code, $parentId, $translations, $sortKey, $localeIds) {
            $group = AuthzResourceGroup::query()->create(['code' => $code, 'parent_id' => $parentId, 'sort_key' => $sortKey]);

            foreach ($translations as $localeCode => $values) {
                $group->authzResourceGroupTranslations()->create([
                    'locale_id' => $localeIds[$localeCode],
                    'display_name' => $values['display_name'],
                    'description' => $values['description'] ?? null,
                ]);
            }

            if ($parentId !== null) {
                $this->copyBlockedFromParent($group, $parentId);
            }

            return $group;
        });
    }

    private function copyBlockedFromParent(AuthzResourceGroup $group, int $parentId): void
    {
        $blocked = AuthzResourceGroupAttribute::query()
            ->where('authz_resource_group_id', $parentId)
            ->where('key', ResourceGroupAttributeKey::Blocked->value)
            ->first();

        if ($blocked !== null) {
            $group->authzResourceGroupAttributes()->create(['key' => $blocked->key, 'value' => $blocked->value]);
        }
    }
}
