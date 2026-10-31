import type { AttachChoice } from './caseActionAttach';
import type { CaseActionNode, CaseActionTarget } from './caseActions';
import type { WorkflowAttachmentLimits, WorkflowCaseShowProps } from '@/types';

/**
 * 処理のダイアログの添付の可否の単体テストが使う props と対象。名前が `.testing.ts` のファイルは `*.test.ts` からだけ import できる。
 */

type Show = Pick<
    WorkflowCaseShowProps,
    'nodeActions' | 'pullBackNodes' | 'canAttachOnWithdraw' | 'attachmentLimits'
>;

export const limits: WorkflowAttachmentLimits = {
    maxFileBytes: 1000,
    maxRequestBytes: 2500,
    maxFiles: 3,
    accept: '.pdf',
    guide: '案内',
    tooLarge: '大きすぎます',
};

export const self = { id: 9, name: '吉田 達也', isSelf: true, canAttach: true };
export const proxy = {
    id: 3,
    name: '高橋 誠',
    isSelf: false,
    canAttach: false,
};

export function nodeAction(
    nodeId: number,
    principals: Show['nodeActions'][number]['principals'],
): Show['nodeActions'][number] {
    return {
        nodeId,
        name: `ステップ ${nodeId}`,
        approve: true,
        approveEarly: false,
        reject: false,
        sendBack: false,
        hold: true,
        release: true,
        transfer: false,
        blockedReason: null,
        holdBlockedReason: null,
        sendBackTargets: [],
        sendBackRestricted: false,
        isCouncilVote: false,
        designations: [],
        principals,
    };
}

/** 承認 = 自分だけ真、保留 = 代理元だけ真、保留解除 = 自分だけ偽・代理元が真(処理者で可否が変わる) */
export const show: Show = {
    nodeActions: [
        nodeAction(11, {
            approve: [self, proxy],
            hold: [
                { ...self, canAttach: false },
                { ...proxy, canAttach: true },
            ],
            release: [
                { ...self, canAttach: false },
                { ...proxy, canAttach: true },
            ],
        }),
        nodeAction(12, { approve: [{ ...self, canAttach: false }] }),
    ],
    pullBackNodes: [
        {
            id: 21,
            name: '引戻す先 1',
            blockedReason: null,
            canAttach: true,
            principalUserId: null,
        },
        {
            id: 22,
            name: '引戻す先 2',
            blockedReason: null,
            canAttach: false,
            principalUserId: 3,
        },
    ],
    canAttachOnWithdraw: true,
    attachmentLimits: limits,
};

export function choice(overrides: Partial<AttachChoice>): AttachChoice {
    return {
        operation: 'approve',
        caseId: 5,
        nodeId: 11,
        principalId: 9,
        ...overrides,
    };
}

export function node(overrides: Partial<CaseActionNode> = {}): CaseActionNode {
    return {
        id: 11,
        name: '部長',
        blockedReason: null,
        principals: [self, proxy],
        fixedPrincipalId: null,
        sendBackTargets: [],
        sendBackRestricted: false,
        isCouncilVote: false,
        assigneeFields: [],
        ...overrides,
    };
}

export function target(
    overrides: Partial<CaseActionTarget> = {},
): CaseActionTarget {
    return {
        operation: 'approve',
        caseId: 5,
        caseName: '申請',
        revision: 4,
        commentMaxLength: 4000,
        nodes: [node()],
        fixedPrincipalId: null,
        ...overrides,
    };
}
