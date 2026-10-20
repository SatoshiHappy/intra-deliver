<?php

namespace App\Queries\Menu;

use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use Illuminate\Support\Arr;

/**
 * 1 回の表示判定で読んだメニューから、表示するグループの木を組む({@see MenuDisplayQuery} が判定ごとに作る)。
 * フォルダ(グループのルートを含む)は子孫に表示するアイテムが 1 つでもあれば出し、外部メニューリンクは出さない
 * (docs の 設計/メニュー/表示.md 3.1 の 3〜5)。
 *
 * 返す形: グループ = {code, name, children}、フォルダ = {code, type: 'folder', name, icon, children}、
 * アイテム = {code, type: 'item', name, icon, href, isExternal}。表示名が無ければ name は null。icon は icon_32 の値か null
 * (lucide のアイコン名。docs の 設計/画面の意匠/サイドバーとアイコン.md 5 節)。使わない列と method は返さない。
 *
 * href(docs の 設計/メニュー/表示.md 3.1 の「`href` と引数」。表示するアイテムのすべてに同じ規則): 引数が無ければ url のまま。あれば url の # から後
 * (フラグメント)を先に分け、前の部分が ? を含めば &、含まなければ ? の後に、引数を名前の順に並べたクエリ文字列(Arr::query = RFC 3986 の形)を付け、
 * フラグメントを戻す。照合と現在地の強調はパスだけで行う(引数は判定と表示の有無を変えない)。
 *
 * @phpstan-type VisibleItem array{code: string, type: string, name: string|null, icon: string|null, href: string, isExternal: bool}
 */
final readonly class VisibleMenuTree
{
    /** @var array<int, list<Menu>> 親の id → 子(並びの順) */
    private array $childrenByParent;

    /**
     * @param  list<Menu>  $descendants  グループのルートの子孫(同じ親の子は並びの順)
     * @param  array<int, string>  $displayNames  メニューの id → 表示名
     * @param  array<int, bool>  $isExternalByVisibleItemId  表示するアイテムの id → 外部サイトのアイテムか
     * @param  array<int, array<string, string>>  $argumentsByItemId  アイテムの id → 引数名 → 引数値(引数の無いアイテムは含まない)
     */
    public function __construct(
        array $descendants,
        private array $displayNames,
        private array $isExternalByVisibleItemId,
        private array $argumentsByItemId,
    ) {
        $childrenByParent = [];

        foreach ($descendants as $menu) {
            $childrenByParent[(int) $menu->parent_id][] = $menu;
        }

        $this->childrenByParent = $childrenByParent;
    }

    /**
     * グループ(ルートのメニュー)。表示するアイテムが子孫に無ければ null。
     *
     * @return array{code: string, name: string|null, children: list<array<string, mixed>>}|null
     */
    public function group(Menu $root): ?array
    {
        $items = [];
        $children = $this->children($root, $items);

        return $children === [] ? null : ['code' => $root->code, 'name' => $this->nameOf($root), 'children' => $children];
    }

    /**
     * グループ(ルートのメニュー)の表示するアイテムを、並びの順に前順でたどって平らにしたもの(フォルダの中はフォルダの位置に入る)。
     * たどりは {@see self::group()} と同じ 1 本(表示するアイテムを木に置くときに、置いた順で集める)。
     *
     * @return list<VisibleItem>
     */
    public function items(Menu $root): array
    {
        $items = [];
        $this->children($root, $items);

        return $items;
    }

    /**
     * @param  list<VisibleItem>  $items  表示するアイテムを置いた順に足す
     * @return list<array<string, mixed>>
     */
    private function children(Menu $parent, array &$items): array
    {
        $nodes = [];

        foreach ($this->childrenByParent[$parent->id] ?? [] as $menu) {
            $node = $this->node($menu, $items);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param  list<VisibleItem>  $items
     * @return array<string, mixed>|null
     */
    private function node(Menu $menu, array &$items): ?array
    {
        if ($menu->type === MenuType::Folder) {
            return $this->folder($menu, $items);
        }

        $item = $menu->type === MenuType::Item ? $this->item($menu) : null;

        if ($item !== null) {
            $items[] = $item;
        }

        return $item;
    }

    /**
     * @param  list<VisibleItem>  $items
     * @return array{code: string, type: string, name: string|null, icon: string|null, children: list<array<string, mixed>>}|null
     */
    private function folder(Menu $folder, array &$items): ?array
    {
        $children = $this->children($folder, $items);

        return $children === [] ? null : ['code' => $folder->code, 'type' => MenuType::Folder->value, 'name' => $this->nameOf($folder), 'icon' => $folder->icon_32, 'children' => $children];
    }

    /**
     * @return VisibleItem|null
     */
    private function item(Menu $item): ?array
    {
        if (! array_key_exists($item->id, $this->isExternalByVisibleItemId)) {
            return null;
        }

        return ['code' => $item->code, 'type' => MenuType::Item->value, 'name' => $this->nameOf($item), 'icon' => $item->icon_32, 'href' => $this->hrefOf($item), 'isExternal' => $this->isExternalByVisibleItemId[$item->id]];
    }

    /**
     * アイテムの href(クラスの説明の規則)。
     */
    private function hrefOf(Menu $item): string
    {
        $url = (string) $item->url;
        $arguments = $this->argumentsByItemId[$item->id] ?? [];

        if ($arguments === []) {
            return $url;
        }

        ksort($arguments, SORT_STRING);
        $fragmentAt = strpos($url, '#');
        $beforeFragment = $fragmentAt === false ? $url : substr($url, 0, $fragmentAt);
        $fragment = $fragmentAt === false ? '' : substr($url, $fragmentAt);

        return $beforeFragment.(str_contains($beforeFragment, '?') ? '&' : '?').Arr::query($arguments).$fragment;
    }

    private function nameOf(Menu $menu): ?string
    {
        return $this->displayNames[$menu->id] ?? null;
    }
}
