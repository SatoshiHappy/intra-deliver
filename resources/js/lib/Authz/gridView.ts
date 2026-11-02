import { nameOrCode } from '@/lib/nameOrCode';
import type { AuthzGridRow } from '@/types';

/** 格子のタブ(画面の中だけの状態) */
export type GridTab = 'settings' | 'judgements';

/**
 * 判定が閉塞のアクション(行を閉塞の行にする)。
 */
export function blockedActions(row: AuthzGridRow): string[] {
    return (row.judgements ?? [])
        .filter((judgement) => judgement.result === 'block')
        .map((judgement) => judgement.action);
}

/**
 * 効いた対象者の表示名(subjectNames = 対象者の ID → 表示名。列に無い ID は #<id>)。
 */
export function decidingNames(
    ids: number[],
    subjectNames: ReadonlyMap<number, string>,
): string[] {
    return ids.map((id) => subjectNames.get(id) ?? `#${id}`);
}

/**
 * 見出しにアクションの段を出すか(アクションが 2 つ以上のとき。「設定」タブの段 3 と「判定」タブの列の見出し)。
 */
export function showsActionRow(actions: string[]): boolean {
    return actions.length >= 2;
}

/**
 * 子のある行の ID の集まり。
 */
export function parentIdsOf(rows: AuthzGridRow[]): Set<number> {
    return new Set(
        rows.flatMap((row) => (row.parentId === null ? [] : [row.parentId])),
    );
}

/**
 * 検索の語(入力の前後の空白を除き、小文字にしたもの)。空なら検索していない。
 */
function searchTermOf(query: string): string {
    return query.trim().toLowerCase();
}

/** 表に出す行と、そのうち開閉の記号を出す行 */
export type VisibleRows = {
    rows: AuthzGridRow[];
    /** 開閉の記号を出す行の ID(子のある行のうち、開閉の状態を見て出す行) */
    toggleable: ReadonlySet<number>;
};

/**
 * 機能の選択肢 = 格子の深さ 1 の行(行の並びのまま。docs の 認可設定の画面.md 2.3 の「機能」)。
 */
export function featureOptions(rows: AuthzGridRow[]): AuthzGridRow[] {
    return rows.filter((row) => row.depth === 1);
}

/**
 * クエリ feature の値(リソースグループのコード)に当たる機能の行。無い値・深さ 1 の行に無い値は null(「すべて」)。
 */
export function featureOf(
    rows: AuthzGridRow[],
    code: string | null,
): AuthzGridRow | null {
    return featureOptions(rows).find((row) => row.code === code) ?? null;
}

/**
 * 表に出す行と開閉の記号(docs の 認可設定の画面.md 2.3「縦軸の絞り込み」の表)。範囲 = 機能(featureId)が null なら
 * すべての行、行を選んだらその行と配下。
 * - 語(searchTermOf)が空: 範囲の外は、選んだ行の祖先だけを出す(記号なし)。範囲の中は、範囲の中の祖先(選んだ行を含み、
 *   行そのものは含めない)に畳んだ行が無ければ出す(子があれば記号を出す)。
 * - 語がある: 範囲の中で表示名(無ければコード)に部分一致(大文字小文字を区別しない)した行と、その祖先を出す(記号なし。
 *   畳んでいても出す)。
 */
export function visibleRows(
    rows: AuthzGridRow[],
    query: string,
    collapsed: ReadonlySet<number>,
    featureId: number | null = null,
): VisibleRows {
    const range = rangeOf(rows, featureId);
    const term = searchTermOf(query);

    if (term === '') {
        return browsedRows(rows, collapsed, range);
    }

    const shown = new Set<number>();

    rows.filter(
        (row) =>
            range.ancestorsInRange(row) !== null &&
            nameOrCode(row).toLowerCase().includes(term),
    ).forEach((row) => {
        shown.add(row.resourceGroupId);
        range.ancestorsOf(row).forEach((id) => shown.add(id));
    });

    return {
        rows: rows.filter((row) => shown.has(row.resourceGroupId)),
        toggleable: new Set(),
    };
}

/** 機能の範囲(選んだ行と配下。機能が無ければすべての行) */
type Range = {
    ancestorsOf: (row: AuthzGridRow) => number[];
    /** 範囲の中の祖先(選んだ行を含み、行そのものは含めない)。範囲の外の行は null */
    ancestorsInRange: (row: AuthzGridRow) => number[] | null;
    /** 選んだ行の祖先(機能が無ければ空) */
    featureAncestors: ReadonlySet<number>;
};

function rangeOf(rows: AuthzGridRow[], featureId: number | null): Range {
    const byId = new Map(rows.map((row) => [row.resourceGroupId, row]));
    const feature = featureId === null ? undefined : byId.get(featureId);
    const ancestors = (row: AuthzGridRow) => ancestorsOf(row, byId);

    return {
        ancestorsOf: ancestors,
        ancestorsInRange: (row) => {
            if (feature === undefined) {
                return ancestors(row);
            }

            if (row.resourceGroupId === feature.resourceGroupId) {
                return [];
            }

            const at = ancestors(row).indexOf(feature.resourceGroupId);

            return at === -1 ? null : ancestors(row).slice(0, at + 1);
        },
        featureAncestors: new Set(
            feature === undefined ? [] : ancestors(feature),
        ),
    };
}

/**
 * 語が空のときの表に出す行: 範囲の外は選んだ行の祖先だけ(記号なし)、範囲の中は範囲の中の祖先に畳んだ行が無い行
 * (子があれば記号を出す)。
 */
function browsedRows(
    rows: AuthzGridRow[],
    collapsed: ReadonlySet<number>,
    range: Range,
): VisibleRows {
    const parents = parentIdsOf(rows);
    const toggleable = new Set<number>();
    const shown = rows.filter((row) => {
        const ancestors = range.ancestorsInRange(row);

        if (ancestors === null) {
            return range.featureAncestors.has(row.resourceGroupId);
        }

        if (ancestors.some((id) => collapsed.has(id))) {
            return false;
        }

        if (parents.has(row.resourceGroupId)) {
            toggleable.add(row.resourceGroupId);
        }

        return true;
    });

    return { rows: shown, toggleable };
}

/**
 * 祖先の行の ID(親から先頭へ)。
 */
function ancestorsOf(
    row: AuthzGridRow,
    byId: ReadonlyMap<number, AuthzGridRow>,
): number[] {
    const ancestors: number[] = [];
    let parentId = row.parentId;

    while (parentId !== null && !ancestors.includes(parentId)) {
        ancestors.push(parentId);
        parentId = byId.get(parentId)?.parentId ?? null;
    }

    return ancestors;
}
