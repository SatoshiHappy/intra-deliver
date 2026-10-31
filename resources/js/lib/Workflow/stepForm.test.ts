import { describe, expect, it } from 'vite-plus/test';
import type { WorkflowStepSettings, WorkflowStepTarget } from '@/types';
import {
    errorKeysOf,
    formOf,
    payloadOf,
    shownErrorKeys,
    stepSignature,
} from './stepForm';

const SEND_BACK = {
    candidates: [
        { code: 'apply', name: '申請' },
        { code: 'section_chief', name: '課長' },
    ],
    selected: ['apply'],
};

function step(overrides: Partial<WorkflowStepSettings>): WorkflowStepSettings {
    return {
        code: 'manager',
        name: '部長',
        kind: 'approve',
        summary: '申請者の上位組織の部長が承認します。',
        target: {
            kind: 'relative',
            base: 'applicant',
            scope: 'parent',
            companyPostId: 7,
        },
        preventsApplicantApproval: true,
        sendBack: SEND_BACK,
        decision: null,
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

describe('formOf', () => {
    it('基準にする人で指定の入力', () => {
        expect(formOf(step({}))).toMatchObject({
            targetKind: 'relative',
            relativeBase: 'applicant',
            relativeScope: 'parent',
            companyPostId: '7',
            prevents: true,
            sendBack: ['apply'],
            decisionRule: '',
        });
    });

    it('役職を絞らない基準にする人は役職が空', () => {
        const target: WorkflowStepTarget = {
            kind: 'relative',
            base: 'previous',
            scope: 'own',
            companyPostId: null,
        };

        expect(formOf(step({ target })).companyPostId).toBe('');
    });
});

describe('formOf(縦配置・対象者)', () => {
    it('縦配置の入力(人数の最小・最大・可決の条件の人数)', () => {
        expect(formOf(COUNCIL)).toMatchObject({
            targetKind: 'designated',
            designatorCode: 'section_chief',
            min: '2',
            max: '5',
            decisionRule: 'count',
            countValue: '2',
            ratioValue: '',
        });
    });

    it('対象者で指定は行を編集中の行にする', () => {
        const target: WorkflowStepTarget = {
            kind: 'fixed',
            rows: [{ type: 'role', id: 3, name: '一般利用者' }],
        };
        const form = formOf(
            step({
                kind: 'apply',
                target,
                preventsApplicantApproval: null,
                sendBack: null,
            }),
        );

        expect(form.targetKind).toBe('fixed');
        expect(form.rows).toEqual([
            { key: 0, type: 'role', id: 3, name: '一般利用者' },
        ]);
        expect(form.prevents).toBe(false);
        expect(form.sendBack).toEqual([]);
    });
});

describe('payloadOf(指定の種類で使わない欄を空で送る)', () => {
    it('基準にする人: 対象者・指定するステップ・人数は null', () => {
        expect(payloadOf(formOf(step({})), step({}))).toEqual({
            target_kind: 'relative',
            targets: null,
            relative_base: 'applicant',
            relative_scope: 'parent',
            company_post_id: 7,
            designator_node_code: null,
            min_assignees: null,
            max_assignees: null,
            prevents_applicant_approval: true,
            send_back_node_codes: ['apply'],
            decision_rule: null,
            decision_value: null,
        });
    });
});

describe('payloadOf(指定の種類を替える)', () => {
    it('指定の種類を替えたら、前の種類の欄は入力が残っていても送らない', () => {
        const base = step({});
        const toFixed = {
            ...formOf(base),
            targetKind: 'fixed' as const,
            rows: [{ key: 0, type: 'user' as const, id: null, name: null }],
        };
        const toDesignated = {
            ...formOf(base),
            targetKind: 'designated' as const,
            designatorCode: 'section_chief',
            min: '1',
            max: '3',
        };

        expect(payloadOf(toFixed, base)).toMatchObject({
            target_kind: 'fixed',
            targets: [{ type: 'user', id: null }],
            relative_base: null,
            relative_scope: null,
            company_post_id: null,
            designator_node_code: null,
            min_assignees: null,
        });
        expect(payloadOf(toDesignated, base)).toMatchObject({
            target_kind: 'designated',
            targets: null,
            relative_base: null,
            company_post_id: null,
            designator_node_code: 'section_chief',
            min_assignees: 1,
            max_assignees: 3,
        });
    });
});

describe('payloadOf(縦配置・持たない設定・数の欄)', () => {
    it('縦配置: 可決の条件が人数なら必要な人数だけ、割合なら割合だけ、全員なら値は null', () => {
        const form = { ...formOf(COUNCIL), ratioValue: '60' };

        expect(payloadOf(form, COUNCIL)).toMatchObject({
            decision_rule: 'count',
            decision_value: 2,
        });
        expect(
            payloadOf({ ...form, decisionRule: 'ratio' }, COUNCIL),
        ).toMatchObject({ decision_rule: 'ratio', decision_value: 60 });
        expect(
            payloadOf({ ...form, decisionRule: 'all' }, COUNCIL),
        ).toMatchObject({ decision_rule: 'all', decision_value: null });
    });

    it('持たない設定は null で送る(申請は申請者承認防止・差戻しできる先・可決の条件を持たない)', () => {
        const apply = step({
            kind: 'apply',
            target: { kind: 'fixed', rows: [] },
            preventsApplicantApproval: null,
            sendBack: null,
        });

        expect(payloadOf(formOf(apply), apply)).toMatchObject({
            prevents_applicant_approval: null,
            send_back_node_codes: null,
            decision_rule: null,
            decision_value: null,
        });
    });
});

describe('payloadOf(数の欄・役職)', () => {
    it('数の欄: 空は null、整数の形は数、ほかは打った文字のまま(サーバーの誤りを欄の下に出す)', () => {
        const form = { ...formOf(COUNCIL), min: '', max: ' 4 ' };
        const typed = { ...formOf(COUNCIL), min: '1.5' };

        expect(payloadOf(form, COUNCIL)).toMatchObject({
            min_assignees: null,
            max_assignees: 4,
        });
        expect(payloadOf(typed, COUNCIL).min_assignees).toBe('1.5');
    });

    it('役職を絞らない基準にする人は company_post_id が null', () => {
        const form = { ...formOf(step({})), companyPostId: '' };

        expect(payloadOf(form, step({})).company_post_id).toBeNull();
    });
});

describe('errorKeysOf', () => {
    it('欄を変えたら、その欄の誤りのキーを返す', () => {
        expect(errorKeysOf('rows')).toEqual(['targets']);
        expect(errorKeysOf('min')).toEqual(['min_assignees']);
        expect(errorKeysOf('decisionRule')).toContain('decision_value');
        expect(errorKeysOf('targetKind')).toContain('relative_base');
    });
});

describe('stepSignature', () => {
    it('設定が替わると識別が替わる', () => {
        expect(stepSignature(step({}))).toBe(stepSignature(step({})));
        expect(stepSignature(step({ summary: '別の文' }))).not.toBe(
            stepSignature(step({})),
        );
    });
});

describe('shownErrorKeys', () => {
    it('欄のキーと、行の誤り(targets.<添字>)を欄の下に出すキーにする。欄の無いキーは含まない', () => {
        const keys = shownErrorKeys(['targets.0', 'targets.3', 'other']);

        expect(keys).toContain('min_assignees');
        expect(keys).toContain('targets.0');
        expect(keys).toContain('targets.3');
        expect(keys).not.toContain('other');
    });
});
