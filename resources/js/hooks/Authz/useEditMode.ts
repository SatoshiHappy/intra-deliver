import { useState } from 'react';
import type { CellEditor } from '@/hooks/Authz/useCellEdit';
import { useCellEdit } from '@/hooks/Authz/useCellEdit';
import {
    canStartEditing,
    operationsFor,
    startedAfter,
} from '@/lib/Authz/editMode';
import type { AuthzCan } from '@/types';

/**
 * 編集の開始と終了(画面の中の状態。サーバーに鍵を持たない。docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 1 節)。
 * 取り直した can が全部偽になれば開始済みを戻す(can が戻っても押さずに編集中にならない)。editor は編集中でセルを押せるときだけ。
 */
export function useEditMode(can: AuthzCan, resourceType: string) {
    const [started, setStarted] = useState(false);
    const cellEditor = useCellEdit(resourceType);
    const canStart = canStartEditing(can);
    const editing = startedAfter(started, can);

    if (started && !editing) {
        // 描画の中で自分の状態を合わせる(React の公式の作法。効果で遅らせると 1 回分、編集中の画面が出る)
        setStarted(false);
    }

    const operations = operationsFor(can, editing);
    const editor: CellEditor | null = operations.editCells ? cellEditor : null;

    return {
        canStart,
        editing,
        operations,
        editor,
        toggle: () => setStarted(!editing),
    };
}
