import { useMemo, useState } from 'react';
import { useOverviewVisit } from '@/hooks/Authz/useOverviewVisit';
import { featureOf, parentIdsOf, visibleRows } from '@/lib/Authz/gridView';
import type { AuthzGridRow } from '@/types';

/** 木の開閉(行の左端の記号) */
export type TreeState = {
    parentIds: ReadonlySet<number>;
    /** 開閉の記号を出す行(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.3「縦軸の絞り込み」の表。visibleRows が決める) */
    toggleable: ReadonlySet<number>;
    isOpen: (resourceGroupId: number) => boolean;
    toggle: (resourceGroupId: number) => void;
};

/**
 * 格子の検索の語と畳んだ行(画面の中だけの状態。種類・判定する人・表示の範囲を替えても残す)と、表に出す行。
 * 出す行と開閉の記号は、表示の範囲(URL のクエリ feature に当たる深さ 1 の行。無ければすべて)・語・畳んだ行から visibleRows が決める
 * (絞り込みは畳んだ状態を変えない)。
 */
export function useGridView(rows: AuthzGridRow[]) {
    const featureId =
        featureOf(rows, useOverviewVisit().feature)?.resourceGroupId ?? null;
    const [query, setQuery] = useState('');
    const [collapsed, setCollapsed] = useState<ReadonlySet<number>>(new Set());
    const visible = useMemo(
        () => visibleRows(rows, query, collapsed, featureId),
        [rows, query, collapsed, featureId],
    );
    const parentIds = useMemo(() => parentIdsOf(rows), [rows]);

    const tree: TreeState = {
        parentIds,
        toggleable: visible.toggleable,
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

    return { query, setQuery, shown: visible.rows, tree };
}
