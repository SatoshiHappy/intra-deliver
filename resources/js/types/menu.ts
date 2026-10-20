import type { RouteStepState, StatusTone } from './status';

/**
 * メニューの表示判定の結果(サーバーの App\Queries\Menu\VisibleMenuTree が返す形。docs の 設計/メニュー/表示.md 3.1)。
 * 表示名が無いとき name は null で、画面はメニュー ID(code)を出す。icon は menus.icon_32 の値(lucide のアイコン名)か null で、
 * 画面は許可表(components/MenuIcon.tsx)から引く(docs の 設計/画面の意匠/サイドバーとアイコン.md 5 節)。
 */
export type MenuItemNode = {
    code: string;
    type: 'item';
    name: string | null;
    icon: string | null;
    href: string;
    isExternal: boolean;
};

export type MenuFolderNode = {
    code: string;
    type: 'folder';
    name: string | null;
    icon: string | null;
    children: MenuNode[];
};

export type MenuNode = MenuItemNode | MenuFolderNode;

export type MenuGroup = {
    code: string;
    name: string | null;
    children: MenuNode[];
};

/**
 * ホームの節(サーバーの App\Queries\Menu\Api\HomeSections::section が返す形。docs の 設計/メニュー/ホーム.md 6.2 の口の @phpstan-type に同じ名前で写す)。
 * 形は kind で判別する共用体。lead はあいさつ欄(area = hero)にだけ、ほかの種類はあいさつ欄の外(top・main・side)にだけ置く。
 */

/** 節の操作(送り先はサーバーが作る。利用者メニューの項目と同じ url・method・data) */
export type HomeAction = {
    label: string;
    url: string;
    method: 'get' | 'post';
    data: Record<string, string | number>;
};

type HomeArea = 'top' | 'main' | 'side';

/** あいさつ欄の一文と主ボタン */
export type HomeLeadSection = {
    code: string;
    kind: 'lead';
    area: 'hero';
    text: string;
    action: HomeAction | null;
};

/** 件数カードの並び。note = 数字の下の内訳の 1 行(無ければ null。行の高さは保つ) */
export type HomeCountersSection = {
    code: string;
    kind: 'counters';
    area: HomeArea;
    heading: string;
    items: {
        code: string;
        label: string;
        icon: string;
        count: number;
        note: string | null;
        url: string;
    }[];
};

/** 表。cells は columns と同じ数・順。more = 面の頭のリンク(語と行き先) */
export type HomeTableSection = {
    code: string;
    kind: 'table';
    area: HomeArea;
    heading: string;
    columns: {
        code: string;
        label: string;
        type: 'title' | 'text' | 'date' | 'datetime' | 'status';
    }[];
    rows: {
        code: string;
        url: string;
        cells: {
            text: string;
            tone: StatusTone | null;
            mark: '✓' | '×' | null;
        }[];
    }[];
    emptyText: string;
    more: { label: string; url: string } | null;
};

/** 操作のタイル(メニューのアイテムから作る) */
export type HomeTilesSection = {
    code: string;
    kind: 'tiles';
    area: HomeArea;
    heading: string;
    items: {
        code: string;
        label: string;
        icon: string | null;
        url: string;
        isExternal: boolean;
    }[];
};

/** 経路のステップ 1 つ(点と線で描く) */
export type HomeRouteStep = {
    code: string;
    label: string;
    state: RouteStepState;
    stateLabel: string;
};

/** 経路の一覧(ステップの点と線) */
export type HomeRoutesSection = {
    code: string;
    kind: 'routes';
    area: HomeArea;
    heading: string;
    items: {
        code: string;
        title: string;
        url: string;
        note: string;
        steps: HomeRouteStep[];
        action: HomeAction | null;
    }[];
    emptyText: string;
    more: { label: string; url: string } | null;
};

/** 人のカード。noteLabel = 全カードに共通の、note の上の小見出し */
export type HomePeopleSection = {
    code: string;
    kind: 'people';
    area: HomeArea;
    heading: string;
    noteLabel: string;
    items: {
        code: string;
        name: string | null;
        affiliation: string | null;
        role: string;
        note: string;
        action: HomeAction;
    }[];
};

export type HomeSection =
    | HomeLeadSection
    | HomeCountersSection
    | HomeTableSection
    | HomeTilesSection
    | HomeRoutesSection
    | HomePeopleSection;
