import { describe, expect, it } from 'vite-plus/test';
import type { WorkflowStepSettings } from '@/types';
import { cardOrderOf, formOf, payloadOf } from './stepForm';
import { errorKeysOf, shownErrorKeys } from './stepFormErrors';
import { stepFieldsOf } from './stepFields';

function step(overrides: Partial<WorkflowStepSettings>): WorkflowStepSettings {
    return {
        code: 'manager',
        name: '部長',
        kind: 'approve',
        summary: ['申請者の上位組織の部長が承認します。'],
        target: {
            kind: 'relative',
            base: 'applicant',
            scope: 'parent',
            companyPostId: 7,
        },
        preventsApplicantApproval: true,
        preventsAttachmentAdd: true,
        sendBack: { candidates: [], selected: [] },
        decision: null,
        deadline: { days: 5 },
        confirmers: [],
        ...overrides,
    };
}

const COUNCIL = step({
    code: 'council',
    kind: 'parallel',
    target: {
        kind: 'designated',
        designatorCode: 'section_chief',
        min: 2,
        max: 5,
    },
    decision: { rule: 'count', value: 2 },
});

describe('カード「添付ファイル」の入力と送る値(承認・横配置・縦配置だけが持つ)', () => {
    it('サーバーの値(追加できない)が入力になり、変えた値(追加できる)がそのまま送る値になる。ほかのカードの値と独立', () => {
        const base = step({});

        expect(formOf(base).preventsAttachmentAdd).toBe(true);
        expect(
            payloadOf({ ...formOf(base), preventsAttachmentAdd: false }, base),
        ).toMatchObject({
            prevents_attachment_add: false,
            prevents_applicant_approval: true,
        });
    });

    it('持つ種類(承認・縦配置)は真偽を送り、持たない種類(分岐)は null で送る', () => {
        const gateway = step({
            kind: 'branch_start',
            target: null,
            preventsApplicantApproval: null,
            preventsAttachmentAdd: null,
            sendBack: null,
        });

        expect(
            payloadOf(formOf(COUNCIL), COUNCIL).prevents_attachment_add,
        ).toBe(true);
        expect(
            payloadOf(formOf(step({})), step({})).prevents_attachment_add,
        ).toBe(true);
        expect(formOf(gateway).preventsAttachmentAdd).toBe(false);
        expect(
            payloadOf(formOf(gateway), gateway).prevents_attachment_add,
        ).toBeNull();
    });

    it('欄を変えたら、その欄の誤りのキーを消す。誤りは欄の下に出すキーに含む', () => {
        expect(errorKeysOf('preventsAttachmentAdd')).toEqual([
            'prevents_attachment_add',
        ]);
        expect(
            shownErrorKeys([], stepFieldsOf('approve', 'fixed', '')),
        ).toContain('prevents_attachment_add');
    });
});

describe('cardOrderOf(出すカードの並び。番号は並びの順)', () => {
    it('添付ファイルは並びの最後(承認・縦配置は 6 番目、横配置は 5 番目、設定を持たないノードは空)', () => {
        const gateway = step({
            kind: 'branch_start',
            target: null,
            preventsApplicantApproval: null,
            preventsAttachmentAdd: null,
            sendBack: null,
            deadline: null,
            confirmers: null,
        });
        const council = { ...COUNCIL, confirmers: null };
        const serial = step({
            kind: 'serial',
            target: COUNCIL.target,
            confirmers: null,
        });

        expect(cardOrderOf(step({})).indexOf('attachment') + 1).toBe(6);
        expect(cardOrderOf(council).indexOf('attachment') + 1).toBe(6);
        expect(cardOrderOf(serial).indexOf('attachment') + 1).toBe(5);
        expect(cardOrderOf(gateway)).toEqual([]);
    });
});
