import { describe, expect, it } from 'vite-plus/test';
import {
    CONTACT_MESSAGE_MAX,
    CONTACT_USER_MAX,
    canAddUser,
    contactPayloadOf,
    defaultPrincipalOf,
    chosenPrincipalOf,
    initialFormOf,
    nodeDefaults,
    noticeErrorOf,
    payloadOf,
    principalNoteOf,
    principalValueOf,
    showsPrincipalField,
} from './caseActionForm';
import type { ActionForm } from './caseActionForm';
import type { CaseActionNode, CaseActionTarget } from './caseActions';

const self = { id: 9, name: '吉田 達也', isSelf: true, canAttach: false };
const takahashi = { id: 3, name: '高橋 誠', isSelf: false, canAttach: false };
const tanaka = { id: 4, name: '田中 花子', isSelf: false, canAttach: false };

function node(overrides: Partial<CaseActionNode> = {}): CaseActionNode {
    return {
        id: 11,
        name: '部長',
        blockedReason: null,
        principals: [],
        fixedPrincipalId: null,
        sendBackTargets: [],
        sendBackRestricted: false,
        isCouncilVote: false,
        assigneeFields: [],
        ...overrides,
    };
}

function target(overrides: Partial<CaseActionTarget> = {}): CaseActionTarget {
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

function form(overrides: Partial<ActionForm> = {}): ActionForm {
    return { ...initialFormOf(target()), ...overrides };
}

const person = { id: 20, code: 'u0020', name: '山田 恵' };

describe('処理担当者の欄', () => {
    it('選択肢が 2 つ以上のときだけ欄を出す', () => {
        expect(showsPrincipalField(undefined)).toBe(false);
        expect(showsPrincipalField(node())).toBe(false);
        expect(showsPrincipalField(node({ principals: [takahashi] }))).toBe(
            false,
        );
        expect(
            showsPrincipalField(node({ principals: [self, takahashi] })),
        ).toBe(true);
        expect(
            showsPrincipalField(node({ principals: [takahashi, tanaka] })),
        ).toBe(true);
    });

    it('既定は先頭: 自分が当たれば自分、当たらなければ先頭の代理元', () => {
        expect(defaultPrincipalOf([self, takahashi])).toBe('9');
        expect(defaultPrincipalOf([takahashi, tanaka])).toBe('3');
        expect(defaultPrincipalOf([])).toBe('');
    });

    it('開いた時とステップを変えた時に、そのステップの先頭を既定にする', () => {
        const nodes = [
            node({ id: 11, principals: [self, takahashi] }),
            node({ id: 12, principals: [takahashi, tanaka] }),
        ];

        expect(initialFormOf(target({ nodes })).principal).toBe('9');
        expect(nodeDefaults(nodes[1]).principal).toBe('3');
    });

    it('代理元を選ぶと「{代理元}さんの代理として{操作}します。…」の 1 行が出て、自分を選ぶと出ない', () => {
        const choices = [self, takahashi];

        expect(principalNoteOf(choices, '3', '保留', '吉田 達也')).toBe(
            '高橋 誠さんの代理として保留します。履歴の処理実行者には、吉田 達也(高橋 誠さんの代理)と記録します。',
        );
        expect(principalNoteOf(choices, '9', '保留', '吉田 達也')).toBeNull();
        expect(principalNoteOf([], '', '保留', '吉田 達也')).toBeNull();
    });
});

describe('principal_user_id: 送る処理者', () => {
    it('自分を選べば null、代理元を選べばその id', () => {
        expect(principalValueOf([self, takahashi], '9', null)).toBeNull();
        expect(principalValueOf([self, takahashi], '3', null)).toBe(3);
    });

    it('欄を出さない選択肢が 1 つの代理元だけのときは、その代理元の id を送る', () => {
        expect(principalValueOf([takahashi], '3', null)).toBe(3);
        expect(principalValueOf([takahashi], '', null)).toBe(3);
    });

    it('選択肢が 1 つの自分なら null', () => {
        expect(principalValueOf([self], '9', null)).toBeNull();
    });

    it('選択肢に無い値は既定(先頭)として扱う', () => {
        expect(principalValueOf([self, takahashi], '99', null)).toBeNull();
    });

    it('選択肢が無い操作(引戻し・取止め)は props の値をそのまま送る', () => {
        expect(principalValueOf([], '', 3)).toBe(3);
        expect(principalValueOf([], '', null)).toBeNull();
    });
});

describe('principal_user_id: payload', () => {
    it('引戻しはステップの値、取止めは案件の値が payload に入る', () => {
        const pullBack = target({
            operation: 'pullBack',
            nodes: [node({ fixedPrincipalId: 3 })],
        });
        const withdraw = target({
            operation: 'withdraw',
            nodes: [],
            fixedPrincipalId: 7,
        });

        expect(
            payloadOf(initialFormOf(pullBack), pullBack, []).principal_user_id,
        ).toBe(3);
        expect(
            payloadOf(initialFormOf(withdraw), withdraw, []).principal_user_id,
        ).toBe(7);
    });

    it('自分として処理する payload は principal_user_id が null', () => {
        const self9 = target({
            nodes: [node({ principals: [self, takahashi] })],
        });
        const data = initialFormOf(self9);

        expect(payloadOf(data, self9, []).principal_user_id).toBeNull();
        expect(
            payloadOf({ ...data, principal: '3' }, self9, []).principal_user_id,
        ).toBe(3);
    });
});

describe('連絡', () => {
    it('人は 10 人まで、ひと言は 1000 文字まで', () => {
        expect(CONTACT_USER_MAX).toBe(10);
        expect(CONTACT_MESSAGE_MAX).toBe(1000);
    });

    it('10 人に達したら追加できず、上限 1 の欄はいつも選び直せる', () => {
        expect(canAddUser(9, CONTACT_USER_MAX)).toBe(true);
        expect(canAddUser(10, CONTACT_USER_MAX)).toBe(false);
        expect(canAddUser(0, 1)).toBe(true);
        expect(canAddUser(1, 1)).toBe(true);
    });

    it('人もひと言も無ければ連絡なし、どちらかがあれば { user_ids, message } を送る', () => {
        expect(contactPayloadOf([], '')).toBeNull();
        expect(contactPayloadOf([], '  ')).toBeNull();
        expect(contactPayloadOf([person], '確認してください')).toEqual({
            user_ids: [20],
            message: '確認してください',
        });
        expect(contactPayloadOf([person], '')).toEqual({
            user_ids: [20],
            message: '',
        });
        expect(contactPayloadOf([], '連絡')).toEqual({
            user_ids: [],
            message: '連絡',
        });
    });

    it('payload の contact は選んだ人とひと言から作り、無ければキーを持たない', () => {
        const t = target();

        expect('contact' in payloadOf(form(), t, [])).toBe(false);
        expect(
            payloadOf(
                form({ contact_users: [person], contact_message: 'どうぞ' }),
                t,
                [],
            ).contact,
        ).toEqual({ user_ids: [20], message: 'どうぞ' });
    });
});

describe('payloadOf: 操作ごとの入力', () => {
    it('担当変更は選んだ人を to_user_id に送り、選ばなければ送らない', () => {
        const transfer = target({ operation: 'transfer' });

        expect(
            payloadOf(form({ to_user: [person] }), transfer, []).to_user_id,
        ).toBe(20);
        expect('to_user_id' in payloadOf(form(), transfer, [])).toBe(false);
        expect(
            'to_user_id' in
                payloadOf(form({ to_user: [person] }), target(), []),
        ).toBe(false);
    });

    it('node_id・comment・revision を送り、差戻しだけ send_back_node_id を送る', () => {
        const sendBack = target({
            operation: 'sendBack',
            nodes: [node({ sendBackTargets: [{ id: 5, name: '課長' }] })],
        });
        const data = initialFormOf(sendBack);
        const payload = payloadOf({ ...data, comment: '直して' }, sendBack, []);

        expect(payload).toMatchObject({
            comment: '直して',
            revision: 4,
            node_id: 11,
            send_back_node_id: 5,
        });
        expect('send_back_node_id' in payloadOf(form(), target(), [])).toBe(
            false,
        );
    });

    it('ステップを持たない操作は node_id を送らない', () => {
        const withdraw = target({ operation: 'withdraw', nodes: [] });

        expect(
            'node_id' in payloadOf(initialFormOf(withdraw), withdraw, []),
        ).toBe(false);
    });
});

describe('選んでいる処理担当者', () => {
    it('選んだ id の選択肢、無ければ先頭(既定)、選択肢が無ければ undefined', () => {
        const choices = [self, takahashi];

        expect(chosenPrincipalOf(choices, '3')).toBe(takahashi);
        expect(chosenPrincipalOf(choices, '')).toBe(self);
        expect(chosenPrincipalOf(choices, '99')).toBe(self);
        expect(chosenPrincipalOf([], '3')).toBeUndefined();
    });
});

describe('ダイアログの上部に出す誤り', () => {
    const errors = {
        case: '案件の誤り',
        principal_user_id: '処理者の誤り',
        attachments: '添付の誤り',
    };
    const two = node({ principals: [self, takahashi] });

    it('案件の誤りが先、欄を出さない処理者・添付の誤りが次(欄を出しているときは寄せない)', () => {
        expect(noticeErrorOf(errors, two, true)).toBe('案件の誤り');
        expect(
            noticeErrorOf({ ...errors, case: undefined }, node(), true),
        ).toBe('処理者の誤り');
        expect(noticeErrorOf({ ...errors, case: undefined }, two, false)).toBe(
            '添付の誤り',
        );
        expect(noticeErrorOf({ attachments: '添付の誤り' }, two, true)).toBe(
            undefined,
        );
    });
});
