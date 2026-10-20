<?php

namespace App\Queries\Menu;

use App\Enums\Authz\ServiceAction;
use App\Enums\Menu\MenuGroupAction;
use App\Enums\Menu\MenuResourceKind;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuArgument;
use App\Models\Menu\MenuTranslation;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\RouteResources;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * メニューの表示判定(docs の 設計/メニュー/表示.md 3.1)。要求ごとに判定し、結果を要求をまたいで覚えない。
 *
 * 1. カテゴリに所属するグループのうち、判定を求める者が d にグループのリソースの read を許されるもの。
 * 2. アイテム: 外部サイトの絶対 URL(http:// か https:// で始まる)は常に表示し、照合に回さない。アプリの中のパスは、メソッドとパスで照合した
 *    アプリのルートが名前を持ち、その名前の URI の execute を許されるとき表示する。照合で合うルートが無い・どちらの形でもない URL は表示しない。
 * 3. フォルダ: 子孫に表示するアイテムが 1 つでもあれば表示する。4. グループ: 1 で許され、ルート(種別 folder)が 3 で表示されるとき表示する。
 * 5. 外部メニューリンク(external)は表示しない。6. 並びは sort_number・code の順。7. 表示名はロケールの行で、無ければ null。
 * 表示するアイテムの href は url に引数(menu_arguments)をクエリ文字列にして付けたもの(規則は {@see VisibleMenuTree})。
 *
 * @phpstan-import-type VisibleItem from VisibleMenuTree
 */
class MenuDisplayQuery
{
    /** グローバルナビ(PC)のカテゴリ */
    public const GLOBAL_NAVIGATION_CATEGORY = 'im_global_nav_pc';

    /** サイトマップ(PC)のカテゴリ */
    public const SITEMAP_CATEGORY = 'im_sitemap_pc';

    /** ホームの主な操作(PC)のカテゴリ(docs の 設計/メニュー/ホーム.md 6.5) */
    public const HOME_QUICK_ACTIONS_CATEGORY = 'home_quick_actions_pc';

    public function __construct(
        private AuthorizationQuery $authorization,
        private RouteResources $routeResources,
    ) {}

    /**
     * カテゴリの表示するグループ(並びの順)。返す形は {@see VisibleMenuTree}。
     *
     * @return list<array{code: string, name: string|null, children: list<array<string, mixed>>}>
     */
    public function visibleGroups(string $categoryCode, Requester $requester, DateTimeInterface $date, string $localeCode): array
    {
        $roots = $this->permittedRoots($categoryCode, $requester, $date);
        $tree = $this->treeOf($roots, $requester, $date, $localeCode);

        return array_values(array_filter($roots->map(fn (Menu $root) => $tree->group($root))->all()));
    }

    /**
     * カテゴリの表示するアイテムを、グループ・フォルダの並びの順にたどって平らにしたもの(ホームの主な操作 = docs の 設計/メニュー/ホーム.md 6.5)。
     * 判定と返すアイテムの形は {@see self::visibleGroups()} と同じ。
     *
     * @return list<VisibleItem>
     */
    public function visibleItems(string $categoryCode, Requester $requester, DateTimeInterface $date, string $localeCode): array
    {
        $roots = $this->permittedRoots($categoryCode, $requester, $date);
        $tree = $this->treeOf($roots, $requester, $date, $localeCode);
        $items = [];

        foreach ($roots as $root) {
            $items = [...$items, ...$tree->items($root)];
        }

        return $items;
    }

    /**
     * 許されたグループのルートの子孫を読み、表示名・表示するアイテム・その引数を 1 回ずつ引いて木を作る。
     *
     * @param  Collection<int, Menu>  $roots
     */
    private function treeOf(Collection $roots, Requester $requester, DateTimeInterface $date, string $localeCode): VisibleMenuTree
    {
        $descendants = $this->descendants($roots);
        $menus = [...$roots->all(), ...$descendants];
        $isExternalByVisibleItemId = $this->isExternalByVisibleItemId($menus, $requester, $date);

        return new VisibleMenuTree($descendants, $this->displayNames($menus, $localeCode), $isExternalByVisibleItemId, $this->argumentsOf(array_keys($isExternalByVisibleItemId)));
    }

    /**
     * カテゴリに所属するグループのルート(種別 folder)のうち、グループのリソースの read を許されるもの(sort_number・code の順)。
     *
     * @return Collection<int, Menu>
     */
    private function permittedRoots(string $categoryCode, Requester $requester, DateTimeInterface $date): Collection
    {
        return Menu::query()
            ->where('type', MenuType::Folder->value)
            ->whereHas('menuGroup.menuGroupCategoryInclusions.menuGroupCategory', fn ($query) => $query->where('code', $categoryCode))
            ->orderBy('sort_number')
            ->orderBy('code')
            ->get()
            ->filter(fn (Menu $root) => $this->authorization->allowsUri($requester, MenuResourceKind::Group->uri($root->code), MenuGroupAction::Read->value, $date))
            ->values();
    }

    /**
     * ルートの子孫(フォルダの下だけをたどる)。浅い順に読み、同じ親の子は 1 回の読み取りで sort_number・code の順に並ぶ。
     *
     * @param  Collection<int, Menu>  $roots
     * @return list<Menu>
     */
    private function descendants(Collection $roots): array
    {
        $descendants = [];
        $parentIds = $roots->pluck('id')->all();

        while ($parentIds !== []) {
            $level = Menu::query()->whereIn('parent_id', $parentIds)->orderBy('sort_number')->orderBy('code')->get();
            $descendants = [...$descendants, ...$level->all()];
            $parentIds = $level->filter(fn (Menu $menu) => $menu->type === MenuType::Folder)->pluck('id')->all();
        }

        return $descendants;
    }

    /**
     * メニューの id → ロケールの表示名(行の無いメニューは含まない)。
     *
     * @param  list<Menu>  $menus
     * @return array<int, string>
     */
    private function displayNames(array $menus, string $localeCode): array
    {
        return MenuTranslation::query()
            ->whereIn('menu_id', array_map(fn (Menu $menu) => $menu->id, $menus))
            ->whereRelation('locale', 'code', $localeCode)
            ->pluck('display_name', 'menu_id')
            ->all();
    }

    /**
     * 表示するアイテムの id → 引数名 → 引数値(引数の無いアイテムは含まない。表示するアイテムの分を 1 回の問い合わせで読む = 表示.md 3.1)。
     *
     * @param  list<int>  $itemIds
     * @return array<int, array<string, string>>
     */
    private function argumentsOf(array $itemIds): array
    {
        $arguments = [];

        foreach (MenuArgument::query()->whereIn('menu_id', $itemIds)->get() as $argument) {
            $arguments[$argument->menu_id][$argument->name] = $argument->value;
        }

        return $arguments;
    }

    /**
     * 表示するアイテムの id → 外部サイトのアイテムか。
     *
     * @param  list<Menu>  $menus
     * @return array<int, bool>
     */
    private function isExternalByVisibleItemId(array $menus, Requester $requester, DateTimeInterface $date): array
    {
        $isExternalById = [];

        foreach (array_filter($menus, fn (Menu $menu) => $menu->type === MenuType::Item) as $item) {
            $url = (string) $item->url;
            $isExternal = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');

            if ($isExternal || $this->allowsAppPath($item, $url, $requester, $date)) {
                $isExternalById[$item->id] = $isExternal;
            }
        }

        return $isExternalById;
    }

    /**
     * アプリの中のパスのアイテム: メソッドとパスで照合したアプリのルートが名前を持ち、その名前の URI の execute を許されるか。
     * アプリの中のパスの形でない URL・合うルートが無い URL・不正な URI は、照合が null を返す({@see RouteResources::match()})。
     */
    private function allowsAppPath(Menu $item, string $url, Requester $requester, DateTimeInterface $date): bool
    {
        $name = $this->routeResources->match($item->method->value, $url)?->getName();

        return $name !== null && $this->authorization->allowsUri($requester, $this->routeResources->uriOf($name), ServiceAction::Execute->value, $date);
    }
}
