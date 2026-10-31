import type { WorkflowSubjectRow, WorkflowSubjectType } from '@/types';

/**
 * 対象者の行の入力(参照者のタブとステップの設定の「対象者で指定」が共有する。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節・3.4 節)。行は種類と、候補の検索で選んだ相手。
 */

/** 種類の選択肢(並びの順。語は認可設定の列を追加と同じ) */
export const SUBJECT_TYPES: { value: WorkflowSubjectType; label: string }[] = [
    { value: 'user', label: 'ユーザー' },
    { value: 'department', label: '組織' },
    { value: 'company_post', label: '役職' },
    { value: 'role', label: 'ロール' },
];

/** 編集中の行。key は行の識別(並びが変わっても入力の状態を保つ)、選んでいない相手は id が null */
export type SubjectRowDraft = {
    key: number;
    type: WorkflowSubjectType;
    id: number | null;
    name: string | null;
};

/** 送る行(選んでいない相手は id を null で送り、サーバーが「対象者を選択してください」を返す) */
export type SubjectRowPayload = {
    type: WorkflowSubjectType;
    id: number | null;
};

/**
 * サーバーの行から編集中の行(key は並びの番号)。
 */
export function draftsOf(rows: WorkflowSubjectRow[]): SubjectRowDraft[] {
    return rows.map((row, index) => ({ ...row, key: index }));
}

/**
 * 行を末尾に足す(種類はユーザー、相手は未選択)。
 */
export function withAddedRow(drafts: SubjectRowDraft[]): SubjectRowDraft[] {
    const key = Math.max(-1, ...drafts.map((draft) => draft.key)) + 1;

    return [...drafts, { key, type: 'user', id: null, name: null }];
}

/**
 * 行の種類を替える(相手は選び直し)。
 */
export function withRowType(
    drafts: SubjectRowDraft[],
    key: number,
    type: WorkflowSubjectType,
): SubjectRowDraft[] {
    return drafts.map((draft) =>
        draft.key === key ? { ...draft, type, id: null, name: null } : draft,
    );
}

/**
 * 行の相手を選ぶ。
 */
export function withRowPicked(
    drafts: SubjectRowDraft[],
    key: number,
    picked: { id: number; name: string },
): SubjectRowDraft[] {
    return drafts.map((draft) =>
        draft.key === key ? { ...draft, ...picked } : draft,
    );
}

/**
 * 行を削除する。
 */
export function withoutRow(
    drafts: SubjectRowDraft[],
    key: number,
): SubjectRowDraft[] {
    return drafts.filter((draft) => draft.key !== key);
}

/**
 * 送る行(並びのまま)。
 */
export function rowsPayloadOf(drafts: SubjectRowDraft[]): SubjectRowPayload[] {
    return drafts.map(({ type, id }) => ({ type, id }));
}

/**
 * サーバーの行の識別(行が替わったら入力を作り直すための key)。
 */
export function rowsSignature(rows: WorkflowSubjectRow[]): string {
    return rows.map((row) => `${row.type}:${row.id}`).join(',');
}
