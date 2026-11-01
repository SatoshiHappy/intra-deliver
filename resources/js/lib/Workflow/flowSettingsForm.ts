/**
 * タブ「設定」の面「添付ファイル」の入力と送る値(docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節)。
 * 入力はサーバーの値から始め、「キャンセル」は同じ関数でサーバーの値に戻す。
 */

/** 入力 = 送る値(欄の名前は 422 のキーと同じ) */
export type FlowSettingsForm = { is_attachment_enabled: boolean };

/** 誤りのキー(画面は必ず真偽を送るので、壊れた送りでしか出ない) */
export const FLOW_SETTINGS_ERROR_KEY = 'is_attachment_enabled';

/**
 * サーバーの値(添付ファイルを使用するか)から入力。
 */
export function flowSettingsFormOf(
    isAttachmentEnabled: boolean,
): FlowSettingsForm {
    return { is_attachment_enabled: isAttachmentEnabled };
}
