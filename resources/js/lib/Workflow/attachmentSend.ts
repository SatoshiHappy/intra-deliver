import type { WorkflowAttachmentLimits } from '@/types';

/**
 * ワークフローの添付を送る形・送る前の確かめ・413 の受け方(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節・4 節)。
 * 申請の画面と処理のダイアログが同じものを使うので、画面の型に依らない。
 */

/** 添付つきで送る値(ファイルの外の入力は封筒の JSON の文字列 1 つにまとめる) */
export type AttachmentEnvelope = { envelope: string; attachments: File[] };

/**
 * 送る値(規則の正本 = 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節)。multipart では型・空の配列・キーの省略が失われるので、
 * ファイルがあるときだけ、ファイルの外の入力を封筒の JSON に入れる。
 */
export function sendShapeOf<T extends object>(
    input: T,
    files: File[],
): T | AttachmentEnvelope {
    return files.length === 0
        ? input
        : { envelope: JSON.stringify(input), attachments: files };
}

/**
 * 送る前の確かめ(件数・1 ファイル・1 回の合計のどれかが上限を超えるなら真 = 送らない)。選んだファイルが無ければ確かめない。
 * 上限は props の添付の上限(画面に数を直書きしない)。
 */
export function exceedsLimits(
    files: { size: number }[],
    limits: Pick<
        WorkflowAttachmentLimits,
        'maxFiles' | 'maxFileBytes' | 'maxRequestBytes'
    >,
): boolean {
    if (files.length === 0) {
        return false;
    }

    const total = files.reduce((sum, file) => sum + file.size, 0);

    return (
        files.length > limits.maxFiles ||
        files.some((file) => file.size > limits.maxFileBytes) ||
        total > limits.maxRequestBytes
    );
}

/** 要求の本文が上限を超えたときの状態 */
const PAYLOAD_TOO_LARGE = 413;

/**
 * 訪問の onHttpException に渡す受け手。状態 413 ならトースト(文は props の tooLarge)を出して false を返す(Inertia の既定のエラーの表示を
 * 止める。ページは替わらず、入力の値と選んだファイルが残る)。ほかの状態は何もせず、Inertia の既定の扱いに任せる。
 */
export function onPayloadTooLarge(
    notify: (message: string) => void,
    message: string,
): (response: { status: number }) => false | undefined {
    return (response) => {
        if (response.status !== PAYLOAD_TOO_LARGE) {
            return undefined;
        }

        notify(message);

        return false;
    };
}
