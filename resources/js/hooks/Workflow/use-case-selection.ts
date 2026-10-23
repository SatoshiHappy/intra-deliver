import { useState } from 'react';
import {
    selectableIds,
    toggledAll,
    toggledOne,
} from '@/lib/Workflow/bulkActions';
import type { BulkAction } from '@/lib/Workflow/bulkActions';
import type { WorkflowCaseRow } from '@/types';

/**
 * 案件一覧の選択(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節: 選択はページの中だけで、タブ・ページ・検索を変えると外す)。
 * listKey = 一覧のクエリ(タブ・切替・ページ・検索の語)。変わったら選択を空にする。選んだ行が描き直しで消えた・選べなくなったら選択から外す。
 */
export function useCaseSelection(
    listKey: string,
    rows: WorkflowCaseRow[],
    action: BulkAction | null,
) {
    const [state, setState] = useState<{ key: string; ids: number[] }>({
        key: listKey,
        ids: [],
    });
    const selectable = action === null ? [] : selectableIds(rows, action);
    const selectedIds =
        state.key === listKey
            ? state.ids.filter((id) => selectable.includes(id))
            : [];

    return {
        selectable,
        selectedIds,
        toggle: (id: number, isChecked: boolean) =>
            setState({
                key: listKey,
                ids: toggledOne(selectedIds, id, isChecked),
            }),
        toggleAll: () =>
            setState({
                key: listKey,
                ids: toggledAll(selectedIds, selectable),
            }),
        clear: () => setState({ key: listKey, ids: [] }),
    };
}
