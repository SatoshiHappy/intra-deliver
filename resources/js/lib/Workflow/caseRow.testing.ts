import type { WorkflowCaseRow } from '@/types';

/**
 * 単体テストが使う一覧の行(未完了の承認待ち。can はすべて偽、タブが求める項目は空)。テストごとに違う値は overrides で渡す。
 * 名前が `.testing.ts` のファイルは `*.test.ts` からだけ import できる(deps:check が止める)。
 */
export function caseRow(
    id: number,
    overrides: Partial<WorkflowCaseRow> = {},
): WorkflowCaseRow {
    return {
        id,
        number: `WF-${String(id).padStart(6, '0')}`,
        name: `申請 ${id}`,
        flowName: 'プロジェクト計画の承認',
        applicant: { id: 1, code: 'u0001', name: '佐々木 陽太' },
        appliedAt: '2026-10-05T00:00:00+00:00',
        currentSummary: { state: 'waiting', name: '部長' },
        currentNodes: [{ id: id * 10, name: '部長', state: 'waiting' }],
        state: 'active',
        completionReason: null,
        revision: 3,
        can: {
            pullBack: false,
            reapply: false,
            withdraw: false,
            editDraft: false,
            deleteDraft: false,
            bulkApprove: false,
            confirm: false,
        },
        pullBackNodes: [],
        canAttachOnWithdraw: false,
        deadline: null,
        principals: [],
        confirmation: null,
        bulkApproval: null,
        ...overrides,
    };
}

/**
 * can の一部だけを真にした can(ほかは偽)。
 */
export function canOf(
    allowed: Partial<WorkflowCaseRow['can']>,
): WorkflowCaseRow['can'] {
    return { ...caseRow(0).can, ...allowed };
}
