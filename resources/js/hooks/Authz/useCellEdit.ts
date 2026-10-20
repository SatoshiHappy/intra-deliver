import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { CellTarget } from '@/lib/Authz/cellEdit';
import { cellKey, cellPayload, isStaleCellMessage } from '@/lib/Authz/cellEdit';
import { EDITED_PROPS } from '@/lib/Authz/editMode';
import { update } from '@/routes/authz/editor/cells';

/** セルを押す口(押せる形のセルが受け取る)。保存中のセルは押せない */
export type CellEditor = {
    isSaving: (target: CellTarget) => boolean;
    press: (target: CellTarget) => void;
};

// 操作を添えたトーストは、既定の 4 秒では押す前に消えるため長く残す
const ACTION_TOAST_MS = 30_000;

/**
 * 誤りをトーストで出す。同時の編集の文には「再読み込み」を添える。
 */
function toastCellErrors(errors: Record<string, string>): void {
    Object.values(errors).forEach((message) =>
        toast.error(
            message,
            isStaleCellMessage(message)
                ? {
                      duration: ACTION_TOAST_MS,
                      action: {
                          label: '再読み込み',
                          onClick: () => router.reload({ only: EDITED_PROPS }),
                      },
                  }
                : undefined,
        ),
    );
}

/**
 * セルを押すたびに 1 回保存する(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.1)。成功のトーストは出さない(セルの表示が結果)。
 * 失敗したセルは元のまま(サーバーが書かなかったため)。保存中のセルは、応答が返るまで押せない(二重送信を防ぐ)。
 */
export function useCellEdit(resourceType: string): CellEditor {
    const [saving, setSaving] = useState<ReadonlySet<string>>(new Set());
    const keyOf = (target: CellTarget) =>
        cellKey(target.resourceGroupId, target.subjectGroupId, target.action);

    const mark = (key: string, isSaving: boolean) =>
        setSaving((current) => {
            const next = new Set(current);

            if (isSaving) {
                next.add(key);
            } else {
                next.delete(key);
            }

            return next;
        });

    const press = (target: CellTarget) => {
        const key = keyOf(target);

        if (saving.has(key)) {
            return;
        }

        router.put(update().url, cellPayload(resourceType, target), {
            only: EDITED_PROPS,
            preserveScroll: true,
            preserveState: true,
            onStart: () => mark(key, true),
            onFinish: () => mark(key, false),
            onError: toastCellErrors,
        });
    };

    return { isSaving: (target) => saving.has(keyOf(target)), press };
}
