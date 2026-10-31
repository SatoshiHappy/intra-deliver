import { describe, expect, it } from 'vite-plus/test';
import { attachableOf, indexAttachOf, showAttachOf } from './caseActionAttach';
import type { AttachChoice, PageAttach } from './caseActionAttach';
import type { CaseActionNode, CaseOperation } from './caseActions';
import { choice, limits, node, show, target } from './caseActionAttach.testing';
import { caseRow } from './caseRow.testing';
import type { WorkflowCaseIndexProps } from '@/types';

type Row = [string, Partial<AttachChoice>, boolean];

const showCases: Row[] = [
    ['承認 × ステップ 11 × 自分', {}, true],
    ['承認 × ステップ 11 × 代理元', { principalId: 3 }, false],
    ['承認 × 処理者が選択肢に無い', { principalId: 99 }, false],
    ['承認 × 処理者なし', { principalId: null }, false],
    ['承認 × ステップ 12(自分も偽)', { nodeId: 12 }, false],
    ['承認 × 行の無いステップ', { nodeId: 99 }, false],
    ['保留 × 自分', { operation: 'hold' }, false],
    ['保留 × 代理元', { operation: 'hold', principalId: 3 }, true],
    ['保留解除 × 自分(案件の管理者として)', { operation: 'release' }, false],
    [
        '保留解除 × 代理元(保留者側)',
        { operation: 'release', principalId: 3 },
        true,
    ],
    ['担当変更 × その操作の選択肢が無い', { operation: 'transfer' }, false],
    [
        '引戻し × ステップ 21',
        { operation: 'pullBack', nodeId: 21, principalId: null },
        true,
    ],
    [
        '引戻し × ステップ 22',
        { operation: 'pullBack', nodeId: 22, principalId: null },
        false,
    ],
    ['引戻し × 行の無いステップ', { operation: 'pullBack', nodeId: 99 }, false],
    [
        '取止め',
        { operation: 'withdraw', nodeId: null, principalId: null },
        true,
    ],
];

describe('showAttachOf: 申請の詳細の選んだ入口の可否', () => {
    it.each(showCases)('%s', (_name, overrides, expected) => {
        expect(showAttachOf(show).canAttach(choice(overrides))).toBe(expected);
    });

    it('取止めは canAttachOnWithdraw が偽なら偽、上限は props のまま', () => {
        const attach = showAttachOf({ ...show, canAttachOnWithdraw: false });

        expect(
            attach.canAttach(choice({ operation: 'withdraw', nodeId: null })),
        ).toBe(false);
        expect(attach.limits).toBe(limits);
        expect(
            showAttachOf({ ...show, attachmentLimits: null }).limits,
        ).toBeNull();
    });
});

const indexCases: Row[] = [
    ['取止め × 真の行', { operation: 'withdraw', nodeId: null }, true],
    [
        '取止め × 偽の行',
        { operation: 'withdraw', caseId: 6, nodeId: null },
        false,
    ],
    [
        '取止め × 一覧に無い行',
        { operation: 'withdraw', caseId: 7, nodeId: null },
        false,
    ],
    ['引戻し × 真のステップ', { operation: 'pullBack', nodeId: 21 }, true],
    ['引戻し × 偽のステップ', { operation: 'pullBack', nodeId: 22 }, false],
    [
        '引戻し × 一覧に無い行',
        { operation: 'pullBack', caseId: 7, nodeId: 21 },
        false,
    ],
    ['承認(一覧の行に入口が無い)', {}, false],
];

describe('indexAttachOf: 申請一覧の行の可否(引戻しと取止めだけ)', () => {
    const rows: WorkflowCaseIndexProps['cases']['data'] = [
        caseRow(5, {
            canAttachOnWithdraw: true,
            pullBackNodes: [
                { id: 21, name: '先 1', blockedReason: null, canAttach: true },
                { id: 22, name: '先 2', blockedReason: null, canAttach: false },
            ],
        }),
        caseRow(6, { canAttachOnWithdraw: false }),
    ];
    const attach = indexAttachOf({
        cases: { data: rows } as WorkflowCaseIndexProps['cases'],
        attachmentLimits: limits,
    });

    it.each(indexCases)('%s', (_name, overrides, expected) => {
        expect(attach.canAttach(choice(overrides))).toBe(expected);
    });

    it('上限は props のまま(null もそのまま)', () => {
        expect(attach.limits).toBe(limits);
        expect(
            indexAttachOf({
                cases: { data: rows } as WorkflowCaseIndexProps['cases'],
                attachmentLimits: null,
            }).limits,
        ).toBeNull();
    });
});

/** 選択肢が無い入口(引戻し・取止め・申請操作の面の保留解除)はステップだけで決まる */
const stepOnlyCases: [
    string,
    CaseOperation,
    CaseActionNode | undefined,
    boolean,
][] = [
    [
        '引戻し × 真のステップ',
        'pullBack',
        node({ id: 21, principals: [] }),
        true,
    ],
    [
        '引戻し × 偽のステップ',
        'pullBack',
        node({ id: 22, principals: [] }),
        false,
    ],
    ['取止め(ステップなし)', 'withdraw', undefined, true],
    ['保留解除(選択肢なし)', 'release', node({ principals: [] }), false],
];

describe('attachableOf: 選んでいるステップと処理者で欄を出すか', () => {
    const attach = showAttachOf(show);

    it('選んだ処理者の canAttach で決まる(選んだ id が選択肢に無い・空なら先頭 = 既定)', () => {
        const attachable = (selected: string) =>
            attachableOf(attach, target(), node(), selected);

        expect([attachable('9'), attachable('3')]).toEqual([true, false]);
        expect([attachable(''), attachable('99')]).toEqual([true, true]);
    });

    it('保留は処理者を替えると真偽が入れ替わる', () => {
        const hold = target({ operation: 'hold' });

        expect(attachableOf(attach, hold, node(), '9')).toBe(false);
        expect(attachableOf(attach, hold, node(), '3')).toBe(true);
    });

    it.each(stepOnlyCases)('%s', (_name, operation, stepNode, expected) => {
        const t = target({ operation });

        expect(attachableOf(attach, t, stepNode, '')).toBe(expected);
    });

    it('上限が無い(どの入口も偽)ときは入口が真でも出さない', () => {
        const none: PageAttach = { limits: null, canAttach: () => true };

        expect(attachableOf(none, target(), node(), '9')).toBe(false);
    });
});
