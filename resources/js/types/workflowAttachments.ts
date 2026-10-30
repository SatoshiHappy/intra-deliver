/**
 * ワークフローの添付の props の型(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 5 節。サーバーの App\Queries\Workflow が返す形)。
 * 数と文はサーバーの設定から作る(画面に直書きしない = 同じ文書の 4 節)。
 */

/** 添付の上限と案内(申請の画面・処理のダイアログが受ける) */
export type WorkflowAttachmentLimits = {
    maxFileBytes: number; // 1 ファイルの上限
    maxRequestBytes: number; // 1 回の合計の上限
    maxFiles: number; // 1 回に送れるファイルの数
    accept: string; // ファイルを選ぶ欄の accept(形式の対応表の拡張子。'.pdf,.png,…')
    guide: string; // 案内の 1 行
    tooLarge: string; // 送る前の確かめのトーストの全文
};

/** 一時保存の添付の行(付けた順) */
type WorkflowDraftAttachment = {
    id: number;
    name: string;
    size: number;
    removable: boolean; // ログインしている利用者が上げた行(「削除」を出す)
};

/** 申請の画面 Workflow/Apply の添付の欄 */
export type WorkflowApplyAttachment = {
    enabled: boolean; // 申請の系統の可否(業務モジュール・フロー)
    limits: WorkflowAttachmentLimits;
    drafts: WorkflowDraftAttachment[]; // 可否によらず入る。新規の申請・再申請は空
    attached: { name: string; size: number }[]; // レコードに付いている添付(再申請・既存のデータからの申請。新しい順に上限まで。表示だけ)
    attachedCount: number; // 付いている添付の全部の件数(「ほかに N 件」= attachedCount − attached の件数)
};
