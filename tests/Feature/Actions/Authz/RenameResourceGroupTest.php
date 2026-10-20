<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateResourceGroup;
use App\Actions\Authz\RenameResourceGroup;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースグループの表示名を変える(docs の 設計/メニュー/認可との結び付け.md 2.4): 入力にあるロケールは行があれば表示名を更新し(説明は変えない)、
 * 無ければ作る(説明は NULL)。入力に無いロケールの行・説明・並び順・ID・親は変えない。リソースグループか、ロケールが無ければ拒み、何も変えない。
 */
final class RenameResourceGroupTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    public function test_updates_the_display_name_of_a_locale_with_a_row_and_keeps_its_description(): void
    {
        $group = $this->group();

        app(RenameResourceGroup::class)->execute('example.renamed', ['ja' => '新しい名前']);

        $this->assertSame(['ja' => ['新しい名前', '説明'], 'en' => ['Old', null]], $this->translations($group));
        $this->assertSame(['example.renamed', $group->parent_id, 3], [$group->refresh()->code, $group->parent_id, $group->sort_key]);
    }

    public function test_creates_the_row_of_a_locale_without_a_row(): void
    {
        $group = app(CreateResourceGroup::class)->execute('example.renamed', null, ['ja' => ['display_name' => '旧名']]);

        app(RenameResourceGroup::class)->execute('example.renamed', ['ja' => '新名', 'en' => 'New']);

        $this->assertSame(['ja' => ['新名', null], 'en' => ['New', null]], $this->translations($group));
    }

    public function test_rejects_a_missing_group_and_an_unknown_locale_and_changes_nothing(): void
    {
        $group = $this->group();
        $before = $this->translations($group);

        $this->assertRejected('resource_group', fn () => app(RenameResourceGroup::class)->execute('example.nothing', ['ja' => '新名']));
        $this->assertRejected('locale', fn () => app(RenameResourceGroup::class)->execute('example.renamed', ['ja' => '新名', 'xx' => '?']));

        $this->assertSame($before, $this->translations($group));
    }

    /**
     * 親・並び順と、ja(説明つき)・en の表示名を持つリソースグループ。
     */
    private function group(): AuthzResourceGroup
    {
        $parent = AuthzResourceGroup::factory()->create();

        return app(CreateResourceGroup::class)->execute('example.renamed', $parent->id, [
            'ja' => ['display_name' => '旧名', 'description' => '説明'],
            'en' => ['display_name' => 'Old'],
        ], 3);
    }

    /**
     * ロケールのコード → [表示名, 説明](ja・en の順)。
     *
     * @return array<string, array{string, string|null}>
     */
    private function translations(AuthzResourceGroup $group): array
    {
        $rows = AuthzResourceGroupTranslation::query()->with('locale')->where('authz_resource_group_id', $group->id)->get();
        $byLocale = [];

        foreach (['ja', 'en'] as $locale) {
            $row = $rows->first(fn (AuthzResourceGroupTranslation $translation) => $translation->locale?->code === $locale);

            if ($row !== null) {
                $byLocale[$locale] = [$row->display_name, $row->description];
            }
        }

        return $byLocale;
    }
}
