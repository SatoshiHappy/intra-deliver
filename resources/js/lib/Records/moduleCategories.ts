import type {
    ModuleCategoryRow,
    RecordModuleSummary,
    UncategorizedModule,
} from '@/types';

/**
 * モジュール分類の画面の共通の値と計算(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4)。
 */

/** 書き込みの後に読み直す props(部分再読み込み。サイドバーは共有プロパティが毎回入る = メニュー/表示.md 3.1 の 8) */
export const MODULE_CATEGORY_PROPS = ['categories', 'uncategorized'];

/** 親をまたぐドラッグで応答が無いとき(通信の失敗など)の文言(4.4。同じ親の中の並べ替えは並び替えの文言のまま) */
export const MOVE_NETWORK_ERROR_MESSAGE =
    'モジュール分類の変更を保存できませんでした。再読み込みしてください';

/**
 * 分類の行の件数(「N 件」。0 件はサイドバーに出ないことを添える)。
 */
export function categoryCountLabel(count: number): string {
    return count === 0 ? '0 件(サイドバーでは非表示)' : `${count} 件`;
}

/**
 * 分類を消したときの中の業務モジュールの行き先の件数: moved = ほかの分類に属さないもの(「業務」の直下の末尾へ移る)、
 * kept = ほかの分類にも属すもの(そちらに残る)。props から数える。
 */
export function deleteOutcome(
    target: ModuleCategoryRow,
    categories: ModuleCategoryRow[],
): { moved: number; kept: number } {
    const elsewhere = new Set(
        categories
            .filter((category) => category.id !== target.id)
            .flatMap((category) =>
                category.modules.map((module) => module.code),
            ),
    );
    const kept = target.modules.filter((module) =>
        elsewhere.has(module.code),
    ).length;

    return { moved: target.modules.length - kept, kept };
}

/** 「分類なし」の見出しの当たりの ID と、「分類なし」の行の ID の接頭辞の名前(分類 ID の形 = 26 文字の英数字と重ならない = 4.4) */
const UNCATEGORIZED = 'uncategorized';

/** 分類の見出しの当たりの ID の接頭辞 */
const CATEGORY_HEADING_PREFIX = 'category:';

/** 業務モジュールの親(分類 ID。null = 「分類なし」) */
export type ModuleParent = string | null;

/** 木の行と当たりの ID の読み方(4.4 の「行の ID」) */
export type TreeNodeId =
    | { kind: 'category'; categoryId: string }
    | { kind: 'heading'; parent: ModuleParent }
    | { kind: 'module'; parent: ModuleParent; code: string };

/** 分類の見出しの当たりの ID(`category:<分類 ID>`)か、「分類なし」の見出しの当たりの ID(`uncategorized`) */
export function headingId(parent: ModuleParent): string {
    return parent === null
        ? UNCATEGORIZED
        : `${CATEGORY_HEADING_PREFIX}${parent}`;
}

/** 業務モジュールの行の ID(分類の中 = `<分類 ID>:<コード>`、「分類なし」= `uncategorized:<コード>`) */
export function moduleRowId(parent: ModuleParent, code: string): string {
    return `${parent ?? UNCATEGORIZED}:${code}`;
}

/** 業務モジュールの並び(分類の中・「分類なし」の表示中)の ID(SortableRows の id = containerId) */
export function moduleListId(parent: ModuleParent): string {
    return `modules:${parent ?? UNCATEGORIZED}`;
}

/**
 * 木の行と当たりの ID を読む。分類の行 = 分類 ID(`:` を含まない)。
 */
export function parseTreeNodeId(id: string): TreeNodeId {
    if (id === UNCATEGORIZED) {
        return { kind: 'heading', parent: null };
    }

    if (id.startsWith(CATEGORY_HEADING_PREFIX)) {
        return {
            kind: 'heading',
            parent: id.slice(CATEGORY_HEADING_PREFIX.length),
        };
    }

    const separator = id.indexOf(':');

    if (separator < 0) {
        return { kind: 'category', categoryId: id };
    }

    const prefix = id.slice(0, separator);

    return {
        kind: 'module',
        parent: prefix === UNCATEGORIZED ? null : prefix,
        code: id.slice(separator + 1),
    };
}

/**
 * 行か見出しの ID の親(業務モジュールの行と見出し)。分類の行は undefined。
 */
export function parentOf(id: string): ModuleParent | undefined {
    const node = parseTreeNodeId(id);

    return node.kind === 'category' ? undefined : node.parent;
}

/**
 * 業務モジュールの今の所属(分類 ID を分類の並びの順に。props の categories[].modules から読む)。
 */
export function membershipOf(
    categories: ModuleCategoryRow[],
    code: string,
): string[] {
    return categories
        .filter((category) =>
            category.modules.some((module) => module.code === code),
        )
        .map((category) => category.id);
}

/**
 * 別の親へ落としたときの目標の所属(分類の並びの順)。移動 = 今の所属 − 元 + 先、コピー = 今の所属 + 先(先・元が「分類なし」なら足す・引くものは無い)。
 * 目標が今の所属と同じなら null(何も送らない = 4.4 の「別の親」)。order = すべての分類 ID の並び。
 */
export function dropTarget(
    current: string[],
    from: ModuleParent,
    to: ModuleParent,
    isCopy: boolean,
    order: string[],
): string[] | null {
    const next = new Set(
        isCopy ? current : current.filter((id) => id !== from),
    );

    if (to !== null) {
        next.add(to);
    }

    const target = order.filter((id) => next.has(id));

    return target.length === current.length &&
        target.every((id) => current.includes(id))
        ? null
        : target;
}

/**
 * 別の親へ落とした後の手元の並び(4.4 の「送り方と成否」): 目標から外れた分類から消し、目標に加わった分類の末尾に足す。今の所属が空で目標が空でなければ
 * 「分類なし」から消し、目標が空になったときだけ「分類なし」の表示中の末尾(非表示の前)に足す。すでにある所は動かさない。
 */
export function treeAfterDrop(
    tree: {
        categories: ModuleCategoryRow[];
        uncategorized: UncategorizedModule[];
    },
    module: RecordModuleSummary,
    current: string[],
    target: string[],
): { categories: ModuleCategoryRow[]; uncategorized: UncategorizedModule[] } {
    const categories = tree.categories.map((category) => {
        const isCurrent = current.includes(category.id);
        const isTarget = target.includes(category.id);

        if (isCurrent && !isTarget) {
            return {
                ...category,
                modules: category.modules.filter(
                    (item) => item.code !== module.code,
                ),
            };
        }

        return !isCurrent && isTarget
            ? { ...category, modules: [...category.modules, module] }
            : category;
    });
    const others = tree.uncategorized.filter(
        (item) => item.code !== module.code,
    );
    const uncategorized =
        target.length > 0
            ? current.length === 0
                ? others
                : tree.uncategorized
            : [
                  ...others.filter((item) => item.isVisible),
                  { ...module, isVisible: true },
                  ...others.filter((item) => !item.isVisible),
              ];

    return { categories, uncategorized };
}

/**
 * 「業務モジュールの追加」の Dialog の一覧(4.4): 表示中で明細でない業務モジュールを、木の上から下へ初めて現れる順(分類の並びの順に中の業務モジュール、
 * 続けて「分類なし」の表示中)。
 */
export function addChoices(
    categories: ModuleCategoryRow[],
    uncategorized: UncategorizedModule[],
): RecordModuleSummary[] {
    const seen = new Set<string>();
    const choices: RecordModuleSummary[] = [];

    for (const module of [
        ...categories.flatMap((category) => category.modules),
        ...uncategorized.filter((item) => item.isVisible),
    ]) {
        if (!seen.has(module.code)) {
            seen.add(module.code);
            choices.push({ code: module.code, name: module.name });
        }
    }

    return choices;
}
