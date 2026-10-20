<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Authz\AuthzSubjectCategory;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の編集の初期データ(docs の 設計/ワークフロー/申請の周辺/初期データ.md 3 節): AuthzSeeder の接頭辞のリソースグループ 3 つとルートのリソース 4 つ
 * (ポリシーは置かない)と、対象者のカテゴリの並び(認証 → ロール → ユーザー → 組織 → 役職)。
 */
final class AuthzEditorSeederTest extends TransactionalTestCase
{
    public function test_seeds_the_prefixes_and_the_route_resources_of_the_editor_without_policies(): void
    {
        $groups = AuthzResourceGroup::query()->where('code', 'like', 'http-services.authz.editor%')
            ->with(['parent', 'authzResourceGroupTranslations.locale', 'authzResource'])->get()
            ->sortBy('code', SORT_STRING)->values();

        $prefix = 'http-services.authz.editor';
        $this->assertSame([
            $prefix => ['http-services.authz', ['en Authorization editing', 'ja 認可設定の編集']],
            "{$prefix}.cells" => [$prefix, ['en Cells', 'ja セル']],
            "{$prefix}.cells.update" => ["{$prefix}.cells", ['en Change a cell', 'ja セルの切替']],
            "{$prefix}.subject-candidates" => [$prefix, ['en Column candidates', 'ja 列を追加の候補']],
            "{$prefix}.subjects" => [$prefix, ['en Columns', 'ja 列']],
            "{$prefix}.subjects.destroy" => ["{$prefix}.subjects", ['en Delete a column', 'ja 列を削除']],
            "{$prefix}.subjects.store" => ["{$prefix}.subjects", ['en Add a column', 'ja 列を追加']],
        ], $groups->mapWithKeys(fn (AuthzResourceGroup $group) => [$group->code => [
            $group->parent?->code,
            $group->authzResourceGroupTranslations
                ->map(fn (AuthzResourceGroupTranslation $name) => "{$name->locale?->code} {$name->display_name}")
                ->sort()->values()->all(),
        ]])->all());
        $this->assertSame([
            'http-services.authz.editor.cells.update' => 'service://authz/editor/cells/update',
            'http-services.authz.editor.subject-candidates' => 'service://authz/editor/subject-candidates',
            'http-services.authz.editor.subjects.destroy' => 'service://authz/editor/subjects/destroy',
            'http-services.authz.editor.subjects.store' => 'service://authz/editor/subjects/store',
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
