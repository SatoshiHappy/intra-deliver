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
export function searchTermOf(query: string): string {
    return query.trim().toLowerCase();
}

/**
 * 表に出す行。検索の語(searchTermOf)が空なら、畳んだ行の配下を除いたすべて。語があれば、表示名(無ければコード)に部分一致
 * (大文字小文字を区別しない)する行とその祖先(畳んでいても出す)。
 */
export function visibleRows(
    rows: AuthzGridRow[],
    query: string,
    collapsed: ReadonlySet<number>,
): AuthzGridRow[] {
    const byId = new Map(rows.map((row) => [row.resourceGroupId, row]));

    const term = searchTermOf(query);

    if (term === '') {
        return rows.filter(
            (row) => !ancestorsOf(row, byId).some((a) => collapsed.has(a)),
        );
    }

    const shown = new Set<number>();

    rows.filter((row) => nameOrCode(row).toLowerCase().includes(term)).forEach(
        (row) => {
            shown.add(row.resourceGroupId);
            ancestorsOf(row, byId).forEach((id) => shown.add(id));
        },
    );

    return rows.filter((row) => shown.has(row.resourceGroupId));
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
