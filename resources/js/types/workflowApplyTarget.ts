import type { RecordFieldValue } from './records';
import type { WorkflowDetailRowInput } from './workflow';

/**
 * 申請の画面の申請対象の props の型(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.3 節。
 * サーバーの App\Queries\Workflow が返す形)。
 */
export type WorkflowApplyTarget = {
    targetField: string | null; // 申請対象の項目のコード(コンテンツに無ければ null)
    targetValues: {
        values: Record<string, RecordFieldValue>;
        details: Record<string, Omit<WorkflowDetailRowInput, 'id'>[]>; // 写しの行は id を持たない
    } | null; // 開いた時に写す値(申請対象からの申請と、申請対象を閲覧できるそのものを申請する画面だけ。ほかは null)
    targetEntry: { module: string; record: number } | null; // 業務レコードの詳細から開いたときのそのレコード(ほかは null)
};
