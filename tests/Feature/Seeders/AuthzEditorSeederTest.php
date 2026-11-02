<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Authz\AuthzSubjectCategory;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の画面と編集の初期データ(docs の 設計/ワークフロー/申請の周辺/初期データ.md 3 節、設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.3):
 * AuthzSeeder の接頭辞のリソースグループ http-services.authz と、その下のルートの束 http-services.authz.settings と、その直下のルートのリソース 5 つ
 * (ポリシーは置かない。URI は名前から決まり、URL は今のまま)と、対象者のカテゴリの並び(認証 → ロール → ユーザー → 組織 → 役職)。
 */
final class AuthzEditorSeederTest extends TransactionalTestCase
{
    public function test_seeds_the_bundle_and_the_route_resources_of_the_screen_and_the_editor_without_policies(): void
    {
        $groups = AuthzResourceGroup::query()->where('code', 'like', 'http-services.authz%')
            ->with(['parent', 'authzResourceGroupTranslations.locale', 'authzResource'])->get()
            ->sortBy('code', SORT_STRING)->values();

        $bundle = 'http-services.authz.settings';
        $this->assertSame([
            'http-services.authz' => ['http-services', ['en Authorization', 'ja 認可']],
            $bundle => ['http-services.authz', ['en Authorization settings', 'ja 認可設定']],
            "{$bundle}.destroy-subject" => [$bundle, ['en Delete a column', 'ja 列を削除']],
            "{$bundle}.show" => [$bundle, ['en Authorization screen', 'ja 認可設定の画面']],
            "{$bundle}.store-subject" => [$bundle, ['en Add a column', 'ja 列を追加']],
            "{$bundle}.subject-candidates" => [$bundle, ['en Column candidates', 'ja 列を追加の候補']],
            "{$bundle}.update-cell" => [$bundle, ['en Change a cell', 'ja セルの切替']],
        ], $groups->mapWithKeys(fn (AuthzResourceGroup $group) => [$group->code => [
            $group->parent?->code,
            $group->authzResourceGroupTranslations
                ->map(fn (AuthzResourceGroupTranslation $name) => "{$name->locale?->code} {$name->display_name}")
                ->sort()->values()->all(),
        ]])->all());
        $this->assertSame([
            "{$bundle}.destroy-subject" => 'service://authz/settings/destroy-subject',
            "{$bundle}.show" => 'service://authz/settings/show',
            "{$bundle}.store-subject" => 'service://authz/settings/store-subject',
            "{$bundle}.subject-candidates" => 'service://authz/settings/subject-candidates',
            "{$bundle}.update-cell" => 'service://authz/settings/update-cell',
        ], $groups->filter(fn (AuthzResourceGroup $group) => $group->authzResource !== null)
            ->mapWithKeys(fn (AuthzResourceGroup $group) => [$group->code => $group->authzResource?->uri])->all());
        $this->assertSame(0, AuthzPolicy::query()->whereIn('authz_resource_group_id', $groups->modelKeys())->count());
        $this->assertSame(['service'], AuthzResource::query()->whereIn('authz_resource_group_id', $groups->modelKeys())
            ->distinct()->pluck('resource_type')->all());
    }

    public function test_the_categories_are_in_the_order_of_the_table(): void
    {
        $this->assertSame(
            ['im_authz_meta_subject', 'b_m_role', 'imm_user', 'imm_department', 'imm_company_post'],
            AuthzSubjectCategory::query()->orderBy('sort_key')->orderBy('code')->pluck('code')->all(),
        );
    }
}
