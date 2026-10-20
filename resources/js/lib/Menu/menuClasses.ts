/**
 * メニューを描く容器に付ける class(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節)。サイドバー・畳んだ帯の飛び出し・サイトマップで
 * 同じ規則を使うため、ここに 1 つだけ置く。
 */

/**
 * 現在地のアイテム(data-current を持つリンク)の見た目と、その中のアイコンの色(4 節の表の (c))。アイテムを描く容器にだけ付ける
 * (フォルダの容器は data-current を持たないので効かない)。
 */
export const currentItemClass =
    'data-[current]:bg-secondary data-[current]:font-bold data-[current]:text-secondary-foreground data-[current]:[&>svg:first-child]:text-secondary-foreground';

/**
 * サイドバーの SidebarMenuButton・SidebarMenuSubButton の中のアイコン(先頭の SVG = MenuIcon)の大きさと色(4 節の表の (b))。
 * 部品の既定の [&>svg]:size-4 などに詳細度で勝つ。2 つ目以降の SVG(フォルダの山形)には当たらない((d))。
 */
export const sidebarIconClass =
    '[&>svg:first-child]:size-5 [&>svg:first-child]:text-muted-foreground';

/**
 * 畳んだ帯の飛び出し(DropdownMenuContent)と、その中のサブメニュー(DropdownMenuSubContent)の幅と余白。
 */
export const flyoutContentClass = 'w-60 p-1.5';
