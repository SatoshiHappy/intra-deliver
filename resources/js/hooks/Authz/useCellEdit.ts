import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { CellTarget } from '@/lib/Authz/cellEdit';
import { cellPayload, isStaleCellMessage } from '@/lib/Authz/cellEdit';
import { EDITED_PROPS } from '@/lib/Authz/editMode';
import { updateCell } from '@/routes/authz/settings';

/** セルを押す口(押せる形のセルが受け取る)。保存の間はどのセルも押せない */
export type CellEditor = {
    saving: boolean;
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

/** 保存の要求を送る口(始まりと終わりの知らせを受ける) */
export type CellPut = (
    payload: ReturnType<typeof cellPayload>,
    handlers: { onStart: () => void; onFinish: () => void },
) => void;

/**
 * 保存の間はどのセルの押下も送らない押す口(保存の印 saving は再描画の後にしか効かず、その前の 2 つ目の押下を止められないので、
 * 閉じた印で止める)。
 */
export function cellPresserOf(
    put: CellPut,
    onSaving: (saving: boolean) => void,
): (payload: ReturnType<typeof cellPayload>) => void {
    let inFlight = false;

    return (payload) => {
        if (inFlight) {
            return;
        }

        inFlight = true;
        put(payload, {
            onStart: () => onSaving(true),
            onFinish: () => {
                inFlight = false;
                onSaving(false);
            },
        });
    };
}

/**
 * セルを押すたびに 1 回保存する(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.1)。成功のトーストは出さない(セルの表示が結果)。
 * 失敗したセルは元のまま(サーバーが書かなかったため)。保存の間に別のセルを押せると、その保存が前の保存の応答(誤りのトーストを含む)を
 * 捨てるため、保存の間は全セルを押せなくする(通知の設定のスイッチと同じ)。
 */
export function useCellEdit(resourceType: string): CellEditor {
    const [saving, setSaving] = useState(false);
    // 押す口は初めの描画で 1 回だけ作る(保存の間の印を描画をまたいで持つ)
    const [presser] = useState(() =>
        cellPresserOf(
            (payload, handlers) =>
                router.put(updateCell().url, payload, {
                    only: EDITED_PROPS,
                    preserveScroll: true,
                    preserveState: true,
                    onError: toastCellErrors,
                    ...handlers,
                }),
            setSaving,
        ),
    );

    return {
        saving,
        press: (target) => presser(cellPayload(resourceType, target)),
    };
}
