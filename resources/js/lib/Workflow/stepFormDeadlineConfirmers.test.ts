import { describe, expect, it } from 'vite-plus/test';
import type { WorkflowStepSettings } from '@/types';
import { cardOrderOf, formOf, payloadOf } from './stepForm';
import { errorKeysOf, shownErrorKeys } from './stepFormErrors';
import { stepFieldsOf } from './stepFields';

/**
 * カード「処理期限」「確認する人」の入力と送る値、カードの並び(docs の 設計/ワークフロー/案件の運用/定義の管理.md の 1 節・2 節、
 * 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節)。期限を持つ種類 = 承認・横配置・縦配置、確認する人を持つ種類 = 申請・承認
 * (サーバーが持たない設定を null で渡す)。
 */

const YAMADA = {
    type: 'user' as const,
    id: 10,
    name: '山田 恵',
    affiliation: '経理部',
};

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
        preventsAttachmentAdd: false,
        sendBack: { candidates: [], selected: [] },
        decision: null,
        deadline: { days: 5 },
        confirmers: [YAMADA],
        ...overrides,
    };
}

const APPLY = step({
    code: 'apply',
    kind: 'apply',
    target: { kind: 'fixed', rows: [] },
    preventsApplicantApproval: null,
    preventsAttachmentAdd: null,
    sendBack: null,
    deadline: null,
});

const DESIGNATED = {
    kind: 'designated' as const,
    designatorCode: 'section_chief',
    min: 2,
    max: 5,
};

const SERIAL = step({
    code: 'line',
    kind: 'serial',
    target: DESIGNATED,
    confirmers: null,
});

const COUNCIL = step({
    code: 'council',
    kind: 'parallel',
    target: DESIGNATED,
    decision: { rule: 'count', value: 2 },
    deadline: { days: null },
    confirmers: null,
});

/** 送る値の欄(5.1 の入力の欄と 2 つの足した欄。どの種類も全部送る) */
const ALL_KEYS = [
    'company_post_id',
    'confirmers',
    'deadline_days',
    'decision_rule',
    'decision_value',
    'designator_node_code',
    'max_assignees',
    'min_assignees',
    'prevents_applicant_approval',
    'prevents_attachment_add',
    'relative_base',
    'relative_scope',
    'send_back_node_codes',
    'target_kind',
    'targets',
];

describe('cardOrderOf(期限と確認する人を含む並び)', () => {
    it('申請 = 誰が申請できるか・確認する人', () => {
        expect(cardOrderOf(APPLY)).toEqual(['target', 'confirmers']);
    });

    it('承認 = 誰が承認するか・申請者本人の承認を防ぐ・差戻しできる先・処理期限・確認する人・添付ファイル', () => {
        expect(cardOrderOf(step({}))).toEqual([
            'target',
            'prevents',
            'sendBack',
            'deadline',
            'confirmers',
            'attachment',
        ]);
    });

    it('横配置・縦配置は処理期限を含み、確認する人を含まない(縦配置は可決の条件の後)', () => {
        expect(cardOrderOf(SERIAL)).toEqual([
            'target',
            'prevents',
            'sendBack',
            'deadline',
            'attachment',
        ]);
        expect(cardOrderOf(COUNCIL)).toEqual([
            'target',
            'prevents',
            'sendBack',
            'decision',
            'deadline',
            'attachment',
        ]);
    });

    it('番号は並びの順(承認は処理期限 4・確認する人 5・添付ファイル 6、申請は確認する人 2)', () => {
        const numberOf = (node: WorkflowStepSettings, card: string) =>
            cardOrderOf(node).findIndex((one) => one === card) + 1;

        expect(
            ['deadline', 'confirmers', 'attachment'].map((card) =>
                numberOf(step({}), card),
            ),
        ).toEqual([4, 5, 6]);
        expect(numberOf(APPLY, 'confirmers')).toBe(2);
        expect(numberOf(COUNCIL, 'deadline')).toBe(5);
    });
});

describe('formOf(期限と確認する人)', () => {
    it('期限の日数は文字にし、期限なし・持たない種類は空。確認する人は札の並び(持たない種類は空)', () => {
        expect(formOf(step({}))).toMatchObject({
            deadline: '5',
            confirmers: [YAMADA],
        });
        expect(formOf(COUNCIL)).toMatchObject({ deadline: '', confirmers: [] });
        expect(formOf(APPLY).deadline).toBe('');
        expect(formOf(step({ deadline: { days: 0 } })).deadline).toBe('0');
    });
});

describe('payloadOf(種類の持てる欄をすべて送る)', () => {
    it.each([
        ['申請', APPLY],
        ['承認', step({})],
        ['横配置', SERIAL],
        ['縦配置', COUNCIL],
    ])('%s: 送る値の欄は全部そろう', (_, node) => {
        expect(Object.keys(payloadOf(formOf(node), node)).sort()).toEqual(
            ALL_KEYS,
        );
    });

    it('期限は数で送り、空欄は null、整数でない文字はそのまま(サーバーが欄の下に返す)。持たない種類は null', () => {
        const node = step({});
        const form = formOf(node);

        expect(payloadOf(form, node).deadline_days).toBe(5);
        expect(payloadOf({ ...form, deadline: '' }, node).deadline_days).toBe(
            null,
        );
        expect(
            payloadOf({ ...form, deadline: '1.5' }, node).deadline_days,
        ).toBe('1.5');
        expect(payloadOf(formOf(APPLY), APPLY).deadline_days).toBeNull();
    });

    it('確認する人はユーザーの id の並びで送り、0 人は空の並び。持たない種類は null', () => {
        const node = step({});
        const form = formOf(node);

        expect(payloadOf(form, node).confirmers).toEqual([10]);
        expect(payloadOf({ ...form, confirmers: [] }, node).confirmers).toEqual(
            [],
        );
        expect(payloadOf(formOf(SERIAL), SERIAL).confirmers).toBeNull();
    });
});

describe('誤りのキー(期限と確認する人)', () => {
    it('欄を変えたらその欄の誤りを消し、誤りは欄の下に出す(確認する人の札の誤り confirmers.<添字> も)', () => {
        expect(errorKeysOf('deadline')).toEqual(['deadline_days']);
        expect(errorKeysOf('confirmers')).toEqual(['confirmers']);
        expect(
            shownErrorKeys(['confirmers.1'], stepFieldsOf('approve', '', '')),
        ).toEqual(
            expect.arrayContaining([
                'deadline_days',
                'confirmers',
                'confirmers.1',
            ]),
        );
    });
});
