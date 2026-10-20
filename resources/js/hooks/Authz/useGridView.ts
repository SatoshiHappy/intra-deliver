import { useMemo, useState } from 'react';
import { parentIdsOf, searchTermOf, visibleRows } from '@/lib/Authz/gridView';
import type { AuthzGridRow } from '@/types';

/** 木の開閉(行の左端の記号) */
export type TreeState = {
    parentIds: ReadonlySet<number>;
    /** searchTermOf(query) !== ''。検索中は開閉の記号を出さない */
    isSearching: boolean;
    isOpen: (resourceGroupId: number) => boolean;
    toggle: (resourceGroupId: number) => void;
};

/**
 * 格子の検索の語と畳んだ行(画面の中だけの状態。種類・判定する人を替えても残す)と、表に出す行。
 * 語があるあいだは、一致した行とその祖先を出す(畳んだ状態は変えない)。
 */
export function useGridView(rows: AuthzGridRow[]) {
    const [query, setQuery] = useState('');
    const [collapsed, setCollapsed] = useState<ReadonlySet<number>>(new Set());
    const shown = useMemo(
        () => visibleRows(rows, query, collapsed),
        [rows, query, collapsed],
    );
    const parentIds = useMemo(() => parentIdsOf(rows), [rows]);

    const tree: TreeState = {
        parentIds,
        isSearching: searchTermOf(query) !== '',
        isOpen: (id) => !collapsed.has(id),
        toggle: (id) =>
            setCollapsed((current) => {
                const next = new Set(current);

                if (!next.delete(id)) {
                    next.add(id);
                }

                return next;
            }),
    };

    return { query, setQuery, shown, tree };
}
