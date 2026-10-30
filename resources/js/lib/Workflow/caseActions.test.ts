import { describe, expect, it } from 'vite-plus/test';
import {
    GUARD_TITLES,
    OPERATION_TEXTS,
    actionNodesOf,
    caseActionTargetOf,
    headerButtonsOf,
    pullBackNodesOf,
} from './caseActions';
import type { CaseOperation } from './caseActions';
import type { WorkflowCaseShowProps } from '@/types';

type NodeAction = WorkflowCaseShowProps['nodeActions'][number];
type Can = WorkflowCaseShowProps['can'];

const self = { id: 9, name: '吉田 達也', isSelf: true };
const principal = { id: 3, name: '高橋 誠', isSelf: false };

function nodeAction(overrides: Partial<NodeAction> = {}): NodeAction {
    return {
        nodeId: 11,
        name: '部長',
        approve: false,
        approveEarly: false,
        reject: false,
        sendBack: false,
        hold: false,
        release: false,
        transfer: false,
        blockedReason: null,
        holdBlockedReason: null,
        sendBackTargets: [],
        sendBackRestricted: false,
        isCouncilVote: false,
        designations: [],
        principals: {},
        ...overrides,
    };
}

function can(overrides: Partial<Can> = {}): Can {
    return {
        pullBack: false,
        reapply: false,
        withdraw: false,
        confirm: false,
        operate: false,
        ...overrides,
    };
}

describe('headerButtonsOf: 見出しの右のボタンの並び', () => {
    it('許される操作だけが、承認 → 承認終了 → 否認 → 差戻し → 保留 → 担当変更 → 引戻し → 再申請 → 取止め → 確認の順に出る', () => {
        const buttons = headerButtonsOf({
            nodeActions: [
                nodeAction({
                    transfer: true,
                    hold: true,
                    sendBack: true,
                    reject: true,
                    approveEarly: true,
                    approve: true,
                }),
            ],
            can: can({
                pullBack: true,
                reapply: true,
                withdraw: true,
                confirm: true,
            }),
        });

        expect(buttons).toEqual([
            { kind: 'dialog', operation: 'approve' },
            { kind: 'dialog', operation: 'approveEarly' },
            { kind: 'dialog', operation: 'reject' },
            { kind: 'dialog', operation: 'sendBack' },
            { kind: 'dialog', operation: 'hold' },
            { kind: 'dialog', operation: 'transfer' },
            { kind: 'dialog', operation: 'pullBack' },
            { kind: 'reapply' },
            { kind: 'dialog', operation: 'withdraw' },
            { kind: 'confirm' },
        ]);
    });
});

describe('headerButtonsOf: 保留・保留解除と、出ない操作', () => {
    it('保留中のステップは保留でなく保留解除が出る', () => {
        const buttons = headerButtonsOf({
            nodeActions: [nodeAction({ release: true, transfer: true })],
            can: can(),
        });

        expect(buttons).toEqual([
            { kind: 'dialog', operation: 'release' },
            { kind: 'dialog', operation: 'transfer' },
        ]);
    });

    it('どのステップでも許されない操作と、can が偽の操作は出ない', () => {
        expect(
            headerButtonsOf({
                nodeActions: [nodeAction(), nodeAction({ nodeId: 12 })],
                can: can(),
            }),
        ).toEqual([]);
    });

    it('どれかのステップで許されれば出る(別のステップで許されなくても)', () => {
        const buttons = headerButtonsOf({
            nodeActions: [nodeAction(), nodeAction({ nodeId: 12, hold: true })],
            can: can({ confirm: true }),
        });

        expect(buttons).toEqual([
            { kind: 'dialog', operation: 'hold' },
            { kind: 'confirm' },
        ]);
    });
});

const rows = [
    nodeAction({
        nodeId: 11,
        name: '課長',
        approve: true,
        hold: true,
        blockedReason: '承認の文言',
        holdBlockedReason: '保留の文言',
        principals: { approve: [self, principal], hold: [principal] },
    }),
    nodeAction({ nodeId: 12, name: '部長', hold: true }),
    nodeAction({ nodeId: 13, name: '経理', sendBack: true }),
];

describe('actionNodesOf: 操作のダイアログで選べるステップ', () => {
    it('その操作を許すステップだけを写した並びで返す', () => {
        expect(actionNodesOf('hold', rows).map((node) => node.id)).toEqual([
            11, 12,
        ]);
        expect(actionNodesOf('approve', rows).map((node) => node.id)).toEqual([
            11,
        ]);
        expect(actionNodesOf('transfer', rows)).toEqual([]);
    });

    it('処理担当者の選択肢は操作ごとの値を使う(キーが無ければ空)', () => {
        expect(actionNodesOf('approve', rows)[0].principals).toEqual([
            self,
            principal,
        ]);
        const hold = actionNodesOf('hold', rows);

        expect(hold[0].principals).toEqual([principal]);
        expect(hold[1].principals).toEqual([]);
    });

    it('指定の欄 assigneeFields は、詳細の props のステップごとの designations を写す', () => {
        const assigneeField = {
            nodeId: 20,
            name: '責任者',
            kind: 'approve' as const,
            kindLabel: '承認',
            min: 1,
            max: 1,
            decision: null,
            initial: [],
        };
        const withField = [
            nodeAction({ approve: true, designations: [assigneeField] }),
        ];

        expect(actionNodesOf('approve', withField)[0].assigneeFields).toEqual([
            assigneeField,
        ]);
    });
});

describe('actionNodesOf: 申請者承認防止の文言', () => {
    it('申請者承認防止の文言は承認・承認終了・否認が blockedReason、保留が holdBlockedReason、差戻しは持たない', () => {
        expect(actionNodesOf('approve', rows)[0].blockedReason).toBe(
            '承認の文言',
        );
        expect(actionNodesOf('hold', rows)[0].blockedReason).toBe('保留の文言');
        const sendBackRows = [
            nodeAction({ sendBack: true, blockedReason: '承認の文言' }),
        ];

        expect(actionNodesOf('sendBack', sendBackRows)[0].blockedReason).toBe(
            null,
        );
    });

    it('申請者承認防止で拒む操作の見出しは承認・承認終了・否認・保留・引戻しだけ', () => {
        const guarded = (
            Object.keys(OPERATION_TEXTS) as CaseOperation[]
        ).filter((operation) => GUARD_TITLES[operation] !== undefined);

        expect(guarded).toEqual([
            'approve',
            'approveEarly',
            'reject',
            'hold',
            'pullBack',
        ]);
        expect(GUARD_TITLES.hold).toBe('保留できません');
    });
});

const detail = {
    caseId: 5,
    caseName: '申請',
    revision: 4,
    commentMaxLength: 4000,
    nodeActions: [nodeAction({ approve: true })],
    pullBackNodes: [
        {
            id: 21,
            name: '課長',
            blockedReason: null,
            canAttach: false,
            principalUserId: null,
        },
        {
            id: 22,
            name: '部長',
            blockedReason: '引戻しの文言',
            canAttach: false,
            principalUserId: 3,
        },
    ],
    casePrincipal: 7,
};

describe('ダイアログの対象の処理者の値', () => {
    it('引戻しは引戻せるステップごとの principalUserId(自分なら null)を送る値にする', () => {
        const nodes = caseActionTargetOf('pullBack', detail).nodes;

        expect(nodes.map((node) => node.fixedPrincipalId)).toEqual([null, 3]);
        expect(nodes.every((node) => node.principals.length === 0)).toBe(true);
        expect(nodes[1].blockedReason).toBe('引戻しの文言');
    });

    it('取止めはステップを持たず、casePrincipal(申請者。自分なら null)を送る値にする', () => {
        const target = caseActionTargetOf('withdraw', detail);

        expect(target.nodes).toEqual([]);
        expect(target.fixedPrincipalId).toBe(7);
        expect(
            caseActionTargetOf('withdraw', { ...detail, casePrincipal: null })
                .fixedPrincipalId,
        ).toBeNull();
    });
});

describe('ダイアログの対象: ステップごとの操作と一覧の行', () => {
    it('ステップごとの操作は案件の値を持たない(処理者はステップの選択肢)', () => {
        const target = caseActionTargetOf('approve', detail);

        expect(target.fixedPrincipalId).toBeNull();
        expect(target.nodes.map((node) => node.id)).toEqual([11]);
        expect(target.revision).toBe(4);
    });

    it('一覧の行の引戻せるステップは principalUserId を持たず、自分として送る', () => {
        const nodes = pullBackNodesOf([
            { id: 1, name: '課長', blockedReason: null, canAttach: false },
        ]);

        expect(nodes[0].fixedPrincipalId).toBeNull();
    });

    it('詳細の引戻せるステップは principalUserId(h の処理者。自分なら null)をそのまま送る値にする', () => {
        const row = {
            id: 1,
            name: '課長',
            blockedReason: null,
            canAttach: false,
        };
        const nodes = pullBackNodesOf([
            { ...row, principalUserId: 7 },
            { ...row, id: 2, principalUserId: null },
        ]);

        expect(nodes.map((node) => node.fixedPrincipalId)).toEqual([7, null]);
    });
});
