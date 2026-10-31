<?php

namespace Database\Seeders;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResourceGroup;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Menu\AddMenu;
use App\Actions\Menu\CreateMenuGroup;
use App\Actions\Menu\CreateMenuGroupCategory;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\MenuItemInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use App\Enums\Menu\MenuGroupAction;
use App\Enums\Menu\MenuResourceKind;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Menu\MenuGroupCategory;
use Illuminate\Database\Seeder;

/**
 * メニューの初期データ(docs の 設計/メニュー/初期データ.md 4 節。ホームの主な操作は 設計/メニュー/ホーム.md 6.5)。
 * グローバルナビ(PC)とサイトマップ(PC)に、GROUPS のグループと同じアイテムを置く。
 *
 * ポリシー: 誰にでも見せるものは、認証状態がゲストユーザか認証済みユーザのどちらか一方なので両方に許す。「管理」のグループにはポリシーを置かず、
 * システム管理者がセットの先頭の許可を継承して見る。ほかのモジュールのルートのリソースと許可は、そのモジュールの seeder が作る。
 * AuthzSeeder(セット http-services)の後に、空のメニューの表へ 1 回入れる前提。すべて業務処理を通す。
 */
class MenuSeeder extends Seeder
{
    use FindsAuthzSubjects;

    /** カテゴリ: コード => [表示名(ja), 表示名(en)] */
    private const CATEGORIES = [
        'im_global_nav_pc' => ['グローバルナビ（PC用）', 'Global navigation (PC)'],
        'im_global_nav_sp' => ['グローバルナビ（スマートフォン用）', 'Global navigation (smartphone)'],
        'im_sitemap_pc' => ['サイトマップ（PC用）', 'Sitemap (PC)'],
        'im_sitemap_sp' => ['サイトマップ（スマートフォン用）', 'Sitemap (smartphone)'],
        'im_personal_settings_pc' => ['個人設定（PC用）', 'Personal settings (PC)'],
        'im_site_help_pc' => ['ヘルプ（PC用）', 'Help (PC)'],
        // ホームの主な操作(本家に無いカテゴリなので接頭辞 im_ を付けない = docs の 設計/メニュー/ホーム.md 6.5)
        'home_quick_actions_pc' => ['ホームの主な操作（PC用）', 'Home quick actions (PC)'],
    ];

    /**
     * グループを置くカテゴリ: カテゴリ => [グループ ID の接頭辞(グループ ID は <接頭辞>-<グループの接尾辞>), 「業務」のグループ ID]。
     * 「業務」のグループ ID は正本の列挙 {@see WorkMenuGroup} の case から取る(文字列を組み立てない)。
     */
    private const GROUP_CATEGORIES = [
        'im_global_nav_pc' => ['global-nav', WorkMenuGroup::GlobalNavigation],
        'im_sitemap_pc' => ['sitemap', WorkMenuGroup::Sitemap],
    ];

    /**
     * グループ(どのカテゴリにも同じものを置く。「業務」main のグループ ID は {@see WorkMenuGroup} の値に限る): グループ ID の接尾辞 => [表示名(ja), 表示名(en)(ルートのメニューの表示名), 並び(ルートの sort_number),
     * ゲストユーザと認証済みユーザに read を許すか, アイテム(並びの順に、ITEMS のルートの名前), その後に並べる申請書の業務モジュールのアイテム(コード)]
     */
    private const GROUPS = [
        'main' => ['業務', 'Work', 0, true, ['menu.home', 'master.organization-chart', 'menu.sitemap'], []],
        // 「フロー定義」「申請操作」「代理の管理」(ルートのリソースは WorkflowSeeder が作る = docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 5 節)
        'admin' => [
            '管理', 'Administration', 100, false,
            [
                'authz.overview', 'records.admin.modules.index', 'records.admin.categories.index',
                'workflow.admin.flows.index', 'workflow.admin.cases.index', 'workflow.admin.proxies.index',
            ],
            [],
        ],
        // ワークフロー(並び 50 = 業務の後・管理の前。ルートのリソースと許可は WorkflowSeeder が作る = docs の 設計/ワークフロー/認可と一覧.md 6 節)
        'workflow' => [
            'ワークフロー', 'Workflow', 50, true, ['workflow.flows.index', 'workflow.cases.index'],
            [WorkflowApplicationModules::PLAN, WorkflowApplicationModules::ISSUE],
        ],
    ];

    /**
     * アイテム: 指すルートの名前 => [パス, 表示名(ja), 表示名(en), アイコン名](アイテムのメニュー ID は <グループ ID>-<ルートの名前の . を - に換えたもの>。
     * アイコン名は lucide の名前 = docs の 設計/画面の意匠/サイドバーとアイコン.md 5.1。画面の許可表 resources/js/components/MenuIcon.tsx に載せる)
     */
    private const ITEMS = [
        'menu.home' => ['/', 'ホーム', 'Home', 'house'],
        'master.organization-chart' => ['/master/organization-chart', '組織図', 'Organization chart', 'network'],
        'menu.sitemap' => ['/menu/sitemap', 'サイトマップ', 'Sitemap', 'map'],
        'authz.overview' => ['/authz/overview', '認可設定', 'Authorization', 'shield-check'],
        'records.admin.modules.index' => ['/records/admin/modules', '業務モジュール', 'Record modules', 'database'],
        'records.admin.categories.index' => ['/records/admin/categories', 'モジュール分類', 'Module categories', 'folder-tree'],
        'workflow.flows.index' => ['/workflow/flows', '申請する', 'Apply', 'send'],
        'workflow.cases.index' => ['/workflow/cases', '申請一覧', 'Application list', 'inbox'],
        'workflow.admin.flows.index' => ['/workflow/admin/flows', 'フロー定義', 'Flow definitions', 'workflow'],
        'workflow.admin.cases.index' => ['/workflow/admin/cases', '申請操作', 'Application operations', 'list-checks'],
        'workflow.admin.proxies.index' => ['/workflow/admin/proxies', '代理の管理', 'Proxy administration', 'user-cog'],
    ];

    /** 申請書の業務モジュールのアイテムが指すルートの名前(業務の一覧) */
    private const RECORD_LIST_ROUTE = 'records.data.index';

    /** ホームの主な操作のカテゴリ・グループ ID・グループの表示名(ja, en)(カテゴリ home_quick_actions_pc にだけ置く。並び 0) */
    private const QUICK_ACTIONS_CATEGORY = 'home_quick_actions_pc';

    private const QUICK_ACTIONS_GROUP = 'home-quick-actions';

    private const QUICK_ACTIONS_NAMES = ['主な操作', 'Quick actions'];

    /**
     * ホームの主な操作のアイテム(並びの順): [指すルートの名前, 引数(引数名 => 引数値), 表示名(ja), 表示名(en), アイコン名]。
     * パスは指すルートのもの(ITEMS)。メニュー ID は <グループ ID>-<ルートの名前の . を - に換えたもの> に、引数があれば -<引数の値> を引数の名前の順に続ける
     * (同じルートを引数違いで置くため。引数の値が英小文字の語である本書の初期データに限る規則)。
     */
    private const QUICK_ACTIONS = [
        ['workflow.flows.index', [], '申請する', 'Apply', 'send'],
        ['workflow.cases.index', ['tab' => 'pending'], '承認する', 'Approve', 'inbox'],
        ['workflow.cases.index', ['tab' => 'processed'], '自分の申請', 'My applications', 'folder'],
        ['master.organization-chart', [], '組織図', 'Organization chart', 'network'],
    ];

    /** この seeder がリソースを作るルート(表示名はアイテムの表示名と同じ。ほかのモジュールのルートのリソースは、そのモジュールの seeder が作る) */
    private const ROUTES = ['menu.home', 'menu.sitemap'];

    public function __construct(
        private CreateResourceGroup $createResourceGroup,
        private CreateMenuGroupCategory $createCategory,
        private CreateMenuGroup $createGroup,
        private AddMenu $addMenu,
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    public function run(): void
    {
        $set = $this->createResourceGroup->execute(MenuGroupAction::SET_CODE, null, [
            'ja' => ['display_name' => 'メニューグループ'],
            'en' => ['display_name' => 'Menu groups'],
        ]);

        foreach (self::CATEGORIES as $code => [$ja, $en]) {
            $this->createCategory->execute($code, ['ja' => $ja, 'en' => $en], isSelectable: true);
        }

        $routeResourceGroupIds = $this->createRouteResources();
        $groupResourceGroupIds = $this->createGroups();
        $everyone = [$this->guestSubjectGroupId($this->findOrCreate), $this->authenticatedSubjectGroupId($this->findOrCreate)];

        foreach ($everyone as $subjectGroupId) {
            foreach ($groupResourceGroupIds as $resourceGroupId) {
                $this->createPolicy->execute($resourceGroupId, $subjectGroupId, MenuGroupAction::RESOURCE_TYPE, MenuGroupAction::Read->value, PolicyEffect::Permit);
            }

            foreach ($routeResourceGroupIds as $resourceGroupId) {
                $this->createPolicy->execute($resourceGroupId, $subjectGroupId, ServiceAction::RESOURCE_TYPE, ServiceAction::Execute->value, PolicyEffect::Permit);
            }
        }

        // ホームの主な操作は認証済みユーザだけに見せる(ゲストには出さない = docs の 設計/メニュー/ホーム.md 6.5)
        $this->createPolicy->execute($this->createQuickActions(), $this->authenticatedSubjectGroupId($this->findOrCreate), MenuGroupAction::RESOURCE_TYPE, MenuGroupAction::Read->value, PolicyEffect::Permit);

        foreach (MenuGroupAction::cases() as $action) {
            $this->createPolicy->execute($set->id, $this->systemAdministratorSubjectGroupId($this->findOrCreate), MenuGroupAction::RESOURCE_TYPE, $action->value, PolicyEffect::Permit);
        }
    }

    /**
     * 接頭辞のリソースグループ http-services.menu と、ルートのリソース。
     *
     * @return list<int> ルートのリソースグループの id
     */
    private function createRouteResources(): array
    {
        $this->createPrefix->execute('menu', ['ja' => ['display_name' => 'メニュー'], 'en' => ['display_name' => 'Menu']]);
        $ids = [];

        foreach (self::ROUTES as $name) {
            [, $ja, $en] = self::ITEMS[$name];
            $ids[] = $this->createRouteResource->execute($name, ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]])->authz_resource_group_id;
        }

        return $ids;
    }

    /**
     * ホームの主な操作のグループとアイテム(引数つき)。
     *
     * @return int グループのリソースグループの id
     */
    private function createQuickActions(): int
    {
        $category = MenuGroupCategory::query()->where('code', self::QUICK_ACTIONS_CATEGORY)->sole();
        [$groupJa, $groupEn] = self::QUICK_ACTIONS_NAMES;
        $group = $this->createGroup->execute($category->id, new MenuFolderInput(self::QUICK_ACTIONS_GROUP, ['ja' => $groupJa, 'en' => $groupEn], sortNumber: 0));

        foreach (self::QUICK_ACTIONS as $sortNumber => [$name, $arguments, $ja, $en, $icon]) {
            ksort($arguments, SORT_STRING);
            $code = implode('-', [self::QUICK_ACTIONS_GROUP, str_replace('.', '-', $name), ...array_values($arguments)]);
            $this->addMenu->execute($group->menu_id, new MenuItemInput($code, self::ITEMS[$name][0], ['ja' => $ja, 'en' => $en], sortNumber: $sortNumber, icon32: $icon, arguments: $arguments));
        }

        return AuthzResourceGroup::query()->where('code', MenuResourceKind::Group->resourceGroupCode(self::QUICK_ACTIONS_GROUP))->sole()->id;
    }

    /**
     * グループのアイテム(ITEMS のルートの並びの順に、続けて申請書の業務モジュールのアイテム)。
     *
     * @param  list<string>  $routeNames
     * @param  list<string>  $moduleCodes
     */
    private function addItems(int $menuId, string $groupCode, array $routeNames, array $moduleCodes): void
    {
        foreach ($routeNames as $sortNumber => $name) {
            [$path, $itemJa, $itemEn, $icon] = self::ITEMS[$name];
            $this->addMenu->execute($menuId, new MenuItemInput($groupCode.'-'.str_replace('.', '-', $name), $path, ['ja' => $itemJa, 'en' => $itemEn], sortNumber: $sortNumber, icon32: $icon));
        }

        $this->addApplicationModuleItems($menuId, $groupCode, $moduleCodes, count($routeNames));
    }

    /**
     * 申請書の業務モジュールの一覧へのアイテム。業務モジュールを作る業務処理が足す「業務」のアイテムと別に、ワークフローの持ち物として置く
     * (docs の 設計/ワークフロー/申請の周辺/メニューと導線.md の 1.1 節)。表示名とアイコンは「業務」のアイテムに合わせる。ID は ITEMS の規則に、
     * 業務モジュールのコードの _ を - に換えて続ける(ほかのアイテムの ID と同じく - だけで書く = 設計/ワークフロー/認可と一覧.md の 6 節)。
     *
     * @param  list<string>  $moduleCodes
     */
    private function addApplicationModuleItems(
        int $menuId,
        string $groupCode,
        array $moduleCodes,
        int $firstSortNumber,
    ): void {
        $sortNumber = $firstSortNumber;
        $routeSegment = str_replace('.', '-', self::RECORD_LIST_ROUTE);

        foreach ($moduleCodes as $moduleCode) {
            $module = WorkflowApplicationModules::MODULES[$moduleCode];
            $code = implode('-', [$groupCode, $routeSegment, str_replace('_', '-', $moduleCode)]);
            $displayNames = ['ja' => $module['name'], 'en' => $module['name']];
            $item = new MenuItemInput(
                $code, "/records/data/{$moduleCode}", $displayNames,
                sortNumber: $sortNumber++, icon32: $module['icon']->value,
            );
            $this->addMenu->execute($menuId, $item);
        }
    }

    /**
     * グローバルナビ(PC)とサイトマップ(PC)のそれぞれに、グループ「業務」「ワークフロー」「管理」とそのアイテム。
     *
     * @return list<int> ゲストユーザと認証済みユーザに read を許すグループのリソースグループの id
     */
    private function createGroups(): array
    {
        $ids = [];

        foreach (self::GROUP_CATEGORIES as $categoryCode => [$prefix, $workGroup]) {
            $category = MenuGroupCategory::query()->where('code', $categoryCode)->sole();

            foreach (self::GROUPS as $suffix => [$ja, $en, $groupSortNumber, $isForEveryone, $routeNames, $modules]) {
                $groupCode = $suffix === 'main' ? $workGroup->value : "{$prefix}-{$suffix}";
                $group = $this->createGroup->execute($category->id, new MenuFolderInput($groupCode, ['ja' => $ja, 'en' => $en], sortNumber: $groupSortNumber));

                $this->addItems($group->menu_id, $groupCode, $routeNames, $modules);

                if ($isForEveryone) {
                    $ids[] = AuthzResourceGroup::query()->where('code', MenuResourceKind::Group->resourceGroupCode($groupCode))->sole()->id;
                }
            }
        }

        return $ids;
    }
}
