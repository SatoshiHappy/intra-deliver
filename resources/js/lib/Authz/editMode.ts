import type { AuthzCan } from '@/types';

/** 編集の操作の後に取り直す props(セルの切替・列の追加と削除の応答のあと。docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.2) */
export const EDITED_PROPS = ['grid', 'judge', 'can'];

/**
 * 開始のボタンを出すか(can のどれかが真のとき。docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 1 節)。
 */
export function canStartEditing(can: AuthzCan): boolean {
    return can.editCells || can.addSubject || can.deleteSubject;
}

/**
 * 開始済みの状態を can に合わせる(can が全部偽になれば戻す。can が戻っても、押さずに編集中にならない)。
 */
export function startedAfter(started: boolean, can: AuthzCan): boolean {
    return started && canStartEditing(can);
}

/**
 * 出す操作。編集中は can が真の操作だけ、編集中でなければ何も出さない。
 */
export function operationsFor(can: AuthzCan, editing: boolean): AuthzCan {
    return editing
        ? { ...can }
        : { editCells: false, addSubject: false, deleteSubject: false };
}
