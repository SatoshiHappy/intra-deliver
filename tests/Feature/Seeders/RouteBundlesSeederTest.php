<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Tests\Support\TransactionalTestCase;

/**
 * 初期データの http-services の形(docs の 設計/メニュー/認可との結び付け.md 2.3 のルートの束と許可の置き場、ADR 0033 の決定 4・6): 接頭辞のリソースグループは
 * 上のフォルダ 6 とルートの束 8 の 14 で、束の子はルートだけ(数は束ごとの固定値)、上のフォルダの子は接頭辞だけ。束と上のフォルダの表示名(ja・en)は
 * 各モジュールの文書の表のとおり。改名の前の接頭辞のリソースグループは無い。設定不可のセットと、その共通マスタの接頭辞の表示名。
 */
final class RouteBundlesSeederTest extends TransactionalTestCase
{
    /** ルートの束 => [表示名(ja), 表示名(en), 子のルートの数] */
    private const BUNDLES = [
        'http-services.authz.settings' => ['認可設定', 'Authorization settings', 5],
        'http-services.master.organization-chart' => ['組織図', 'Organization chart', 1],
        'http-services.records.admin.categories' => ['モジュール分類', 'Module categories', 9],
        'http-services.records.admin.comments' => ['コメントの管理', 'Comment administration', 1],
        'http-services.records.admin.modules' => ['業務モジュール', 'Record modules', 19],
        'http-services.workflow.admin.cases' => ['申請操作', 'Application operations', 6],
        'http-services.workflow.admin.flows' => ['フロー定義', 'Flow definitions', 9],
        'http-services.workflow.admin.proxies' => ['代理の管理', 'Proxy administration', 3],
    ];

    /** 束より上のフォルダ => [表示名(ja), 表示名(en)] */
    private const FOLDERS = [
        'http-services.authz' => ['認可', 'Authorization'],
        'http-services.master' => ['基本データ', 'Basic data'],
        'http-services.records' => ['業務データ', 'Records'],
        'http-services.records.admin' => ['業務データの管理', 'Records administration'],
        'http-services.workflow' => ['ワークフロー', 'Workflow'],
        'http-services.workflow.admin' => ['ワークフローの管理', 'Workflow administration'],
    ];

    /** 改名で無くなる接頭辞のリソースグループ(事実の README の 2 節) */
    private const RETIRED_PREFIXES = [
        'http-services.authz.editor', 'http-services.authz.editor.cells', 'http-services.authz.editor.subjects',
        'http-services.records.admin.fields', 'http-services.records.admin.headings',
        'http-services.records.admin.related-columns', 'http-services.records.admin.rules',
        'http-services.workflow.admin.flows.referrers', 'http-services.workflow.admin.flows.settings',
        'http-services.workflow.admin.versions', 'http-services.workflow.admin.nodes',
    ];

    public function test_the_prefixes_of_http_services_are_the_six_folders_and_the_eight_bundles(): void
    {
        $expected = [...array_keys(self::FOLDERS), ...array_keys(self::BUNDLES)];
        $actual = array_keys($this->prefixesOf('http-services'));
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_every_bundle_has_only_routes_as_its_children_in_the_number_of_the_table(): void
    {
        $children = [];

        foreach (array_keys(self::BUNDLES) as $code) {
            $group = $this->group($code);
            $children[$code] = [
                $group->children->filter(fn (AuthzResourceGroup $child) => $child->authzResource !== null)->count(),
                $group->children->count(),
            ];
        }

        $this->assertSame(array_map(fn (array $bundle) => [$bundle[2], $bundle[2]], self::BUNDLES), $children);
        $this->assertSame(53, array_sum(array_column(self::BUNDLES, 2)));
    }

    public function test_every_folder_above_the_bundles_has_only_prefixes_as_its_children(): void
    {
        $routes = [];

        foreach (array_keys(self::FOLDERS) as $code) {
            $routes[$code] = $this->group($code)->children
                ->filter(fn (AuthzResourceGroup $child) => $child->authzResource !== null)->count();
        }

        $this->assertSame(array_fill_keys(array_keys(self::FOLDERS), 0), $routes);
    }

    public function test_the_bundles_and_the_folders_have_the_display_names_of_the_tables(): void
    {
        $expected = [];
        $actual = [];

        foreach ([...self::FOLDERS, ...self::BUNDLES] as $code => $names) {
            $expected[$code] = ['en' => $names[1], 'ja' => $names[0]];
            $actual[$code] = $this->displayNames($code);
        }

        $this->assertSame($expected, $actual);
    }

    public function test_the_unmanaged_set_and_its_common_master_prefix_have_the_display_names_of_the_tables(): void
    {
        $this->assertSame(
            ['en' => 'HTTP services (unmanaged)', 'ja' => 'HTTPサービス(設定不可)'],
            $this->displayNames('http-services-unmanaged'),
        );
        $this->assertSame(
            ['en' => 'Basic data', 'ja' => '基本データ'],
            $this->displayNames('http-services-unmanaged.master'),
        );
    }

    public function test_no_prefix_retired_by_the_renaming_remains(): void
    {
        $remaining = AuthzResourceGroup::query()->whereIn('code', self::RETIRED_PREFIXES)->pluck('code')->all();

        $this->assertSame([], $remaining);
    }

    /**
     * セットの配下の接頭辞のリソースグループ(リソースを持たないグループ)。
     *
     * @return array<string, AuthzResourceGroup>
     */
    private function prefixesOf(string $setCode): array
    {
        $prefixes = [];

        for ($level = [AuthzResourceGroup::query()->where('code', $setCode)->sole()->id]; $level !== [];) {
            $groups = AuthzResourceGroup::query()->whereIn('parent_id', $level)->with('authzResource')->get();
            $level = $groups->modelKeys();

            foreach ($groups->filter(fn (AuthzResourceGroup $group) => $group->authzResource === null) as $group) {
                $prefixes[$group->code] = $group;
            }
        }

        return $prefixes;
    }

    private function group(string $code): AuthzResourceGroup
    {
        return AuthzResourceGroup::query()->where('code', $code)->with('children.authzResource')->sole();
    }

    /**
     * @return array<string, string> ロケールのコード => 表示名(ロケールの順)
     */
    private function displayNames(string $code): array
    {
        return AuthzResourceGroup::query()->where('code', $code)->with('authzResourceGroupTranslations.locale')->sole()
            ->authzResourceGroupTranslations
            ->mapWithKeys(fn (AuthzResourceGroupTranslation $name) => [
                (string) $name->locale?->code => $name->display_name,
            ])
            ->sortKeys()->all();
    }
}
