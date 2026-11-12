import type { ClearedField } from './recordCopy';

/**
 * 案件からの複製で開いた申請の画面の props copy(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/写す値.md の
 * 13.5 節。サーバーの App\Queries\Workflow が返す形)。ほかの開き方と、開いた後の GET では null(画面が開いた時の値を保つ)。
 */
export type WorkflowApplyCopy = {
    caseId: number; // 元の案件の id(キャンセルの移り先)
    caseNumber: string; // 表示の申請番号(見出しの下の 1 行)
    name: string; // 元の申請名(見出しの下の 1 行)
    clearedFields: ClearedField[]; // 開いた時に使えない値を空にした欄(画面の欄の順。無ければ空)
    rowAccess: string; // 引き継ぐ参照制限の暗号化した値(申請・一時保存の新規の POST の copy_row_access に送り返す)
};
