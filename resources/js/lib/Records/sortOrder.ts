import type { Announcements, UniqueIdentifier } from '@dnd-kit/core';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { toastErrors } from '@/lib/flashToast';

/**
 * 取っ手のドラッグでの並び替えの共通の部品(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3 の項目の並び替えと、
 * モジュール分類/画面と検査.md 4.4 の分類の木。どちらも同じ操作と成否の扱い)。
 */

/** 応答が無いとき(通信の失敗など)の文言。保存されたかは分からないので、再読み込みを促す(管理の画面.md 3.3。文言は実装で決めた) */
const NETWORK_ERROR_MESSAGE =
    '並び替えを保存できませんでした。再読み込みしてください';

/** キーボードでの並び替えの読み上げ(dnd-kit の既定は英語なので ja にする) */
export const SORT_SCREEN_READER_INSTRUCTIONS = {
    draggable:
        'Space キーで移動を開始し、上下の矢印キーで移動し、もう一度 Space キーで配置します。Escape キーで中止します。',
};

/**
 * 並び替えの読み上げ(行の名前で言う)。
 */
export function sortAnnouncements(
    nameOf: (id: UniqueIdentifier | undefined) => string,
): Announcements {
    return {
        onDragStart: ({ active }) => `${nameOf(active.id)}の移動を開始しました`,
        onDragOver: ({ active, over }) =>
            over === null
                ? undefined
                : `${nameOf(active.id)}を${nameOf(over.id)}の位置へ移動しました`,
        onDragEnd: ({ active, over }) =>
            over === null
                ? `${nameOf(active.id)}は元の位置のままです`
                : `${nameOf(active.id)}を${nameOf(over.id)}の位置に配置しました`,
        onDragCancel: ({ active }) =>
            `並び替えを中止しました。${nameOf(active.id)}は元の位置のままです`,
    };
}

/**
 * 新しい並びを put で送り、only の props だけを読み直す(スクロールの位置を保つ)。成功はトーストなし、422 は文言をトースト、
 * 応答が無いときもトースト(文言 = networkErrorMessage。省略 = 並び替えの文言。モジュール分類の画面の親をまたぐドラッグは呼び手が渡す)。
 * onFinish は成否に依らず最後に呼ばれる(Inertia は props を更新してから onSuccess・onError を呼び、最後に onFinish を呼ぶ =
 * 呼び手はそこで手元の並びを捨てて props に戻る)。
 */
export function putOrder(
    url: string,
    data: Record<string, string[]>,
    only: string[],
    onFinish: () => void,
    networkErrorMessage: string = NETWORK_ERROR_MESSAGE,
): void {
    router.put(url, data, {
        only,
        preserveScroll: true,
        onFinish,
        onError: toastErrors,
        onNetworkError: () => {
            toast.error(networkErrorMessage);

            return false;
        },
    });
}
