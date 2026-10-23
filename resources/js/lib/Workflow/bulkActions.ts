import { EMPTY_VALUE } from '@/lib/recordValues';
import type {
    StatusTone,
    WorkflowBulkOutcome,
    WorkflowBulkResult,
    WorkflowCaseRow,
    WorkflowCaseTab,
} from '@/types';

/**
 * 案件一覧の選択と一括の操作(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節の「選択と一括」・3 節)。
 * 画面は組を組み立てず、行が示した組をそのまま送る(案件の操作/操作.md の 10.7 節)。
 */

/** 一括の操作(承認 = 未処理のタブ、確認 = 未確認のタブ) */
export type BulkAction = WorkflowBulkResult['action'];

/** 操作ごとの文言(題・説明・注意の囲みの文・ボタン・選べない行の読み上げ・結果の題と文。案件の画面.md の 2 節・3 節) */
export const BULK_TEXTS: Record<
    BulkAction,
    {
        verb: string;
        submitLabel: (count: number) => string;
        title: (count: number) => string;
        description: string;
        notice: string;
        notSelectable: string;
        resultTitle: string;
    }
> = {
    approve: {
        verb: '承認',
        submitLabel: (count) => `選択した ${count} 件を承認`,
        title: (count) => `選択した ${count} 件を承認する`,
        description: '同じコメントで、選択した申請をまとめて承認します。',
        notice: '処理できない申請があると、その申請で止まります。止まる前の申請は承認済みのままです。',
        notSelectable: 'この申請は一括承認できません',
        resultTitle: '一括承認の結果',
    },
    confirm: {
        verb: '確認',
        submitLabel: (count) => `選択した ${count} 件を確認`,
        title: (count) => `選択した ${count} 件を確認する`,
        description: '選択した申請の、確認するステップをまとめて確認します。',
        notice: '処理できない申請があると、その申請で止まります。止まる前の申請は確認済みのままです。',
        notSelectable: 'この申請は一括確認できません',
        resultTitle: '一括確認の結果',
    },
};

/** 一括承認だけの表の下の 1 行 */
export const BULK_APPROVE_RULE =
    '一括承認できるのは、自分が承認できるステップがちょうど 1 つの申請です。次のステップの指定が必要な申請と、申請者承認防止に該当する申請は選択できません。';

/** 結果の表の、閲覧できない申請の文 */
export const UNAVAILABLE_CASE = 'この申請は閲覧できません。';

/** 結果の札(承認済み・確認済み = 承認待ちの色と印、処理済み = 処理の結果の色、未処理 = 差戻しの色 = 画面/README.md の 8.1 節) */
export const BULK_OUTCOMES: Record<
    WorkflowBulkOutcome,
    { label: string; tone: StatusTone; mark: string | null }
> = {
    approved: { label: '承認済み', tone: 'pending', mark: '✓' },
    confirmed: { label: '確認済み', tone: 'pending', mark: '✓' },
    processed: { label: '処理済み', tone: 'settled', mark: null },
    pending: { label: '未処理', tone: 'returned', mark: null },
};

/**
 * 結果の表の対応の列の文(対応の文が無い = 承認・確認できた行は「—」)。
 */
export function guidanceText(guidance: string | null): string {
    return guidance ?? EMPTY_VALUE;
}

/**
 * タブの一括の操作(未処理 = 承認、未確認 = 確認、ほかは無し = 選択の欄を出さない)。
 */
export function bulkActionOf(tab: WorkflowCaseTab): BulkAction | null {
    switch (tab) {
        case 'pending':
            return 'approve';
        case 'unconfirmed':
            return 'confirm';
        default:
            return null;
    }
}

/**
 * 行を選べるか(承認 = can.bulkApprove、確認 = can.confirm)。
 */
function isSelectable(row: WorkflowCaseRow, action: BulkAction): boolean {
    return action === 'approve' ? row.can.bulkApprove : row.can.confirm;
}

/**
 * そのページの選べる行の id(一覧の並び)。
 */
export function selectableIds(
    rows: WorkflowCaseRow[],
    action: BulkAction,
): number[] {
    return rows.filter((row) => isSelectable(row, action)).map((row) => row.id);
}

/**
 * 「すべて選択」を押した後の選択(選べる行がすべて選ばれていれば外し、ほかはそのページの選べる行のすべて)。
 */
export function toggledAll(selected: number[], selectable: number[]): number[] {
    const isAllSelected =
        selectable.length > 0 &&
        selectable.every((id) => selected.includes(id));

    return isAllSelected ? [] : [...selectable];
}

/**
 * 行の選択の欄を変えた後の選択。
 */
export function toggledOne(
    selected: number[],
    id: number,
    isChecked: boolean,
): number[] {
    const others = selected.filter((selectedId) => selectedId !== id);

    return isChecked ? [...others, id] : others;
}

/**
 * 「すべて選択」の欄の状態(全部 = 真、一部 = 'indeterminate'、無し = 偽)。
 */
export function allCheckedState(
    selected: number[],
    selectable: number[],
): boolean | 'indeterminate' {
    const count = selectable.filter((id) => selected.includes(id)).length;

    if (count === 0) {
        return false;
    }

    return count === selectable.length ? true : 'indeterminate';
}

/**
 * 選んだ行(一覧の並び = 処理の順)。
 */
export function selectedRowsOf(
    rows: WorkflowCaseRow[],
    selected: number[],
): WorkflowCaseRow[] {
    return rows.filter((row) => selected.includes(row.id));
}

/**
 * 一括承認の送る形(各行 = 行の id・改訂番号と、行が示した組のノードと処理者。自分なら null)。
 */
export function bulkApprovePayload(rows: WorkflowCaseRow[], comment: string) {
    return {
        cases: rows.map((row) => ({
            id: row.id,
            revision: row.revision,
            node_id: row.bulkApproval?.nodeId ?? null,
            principal_user_id: row.bulkApproval?.principal?.id ?? null,
        })),
        comment,
    };
}

/**
 * 一括確認の送る形。
 */
export function bulkConfirmPayload(rows: WorkflowCaseRow[]) {
    return { case_ids: rows.map((row) => row.id) };
}

/**
 * 結果のダイアログの上の文。
 */
export function resultSummaryOf(result: WorkflowBulkResult): string {
    const { verb } = BULK_TEXTS[result.action];
    const rest = result.total - result.succeeded;

    return `${result.total} 件のうち ${result.succeeded} 件を${verb}しました。残りの ${rest} 件は、下の表の「対応」を確認してください。`;
}
