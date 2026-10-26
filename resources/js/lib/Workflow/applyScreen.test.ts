import { describe, expect, it } from 'vite-plus/test';
import {
    blockerOf,
    headingLineOf,
    isFieldKey,
    nextText,
    operationOf,
    showsSaveDraft,
} from './applyScreen';
import type { WorkflowApplyProps } from '@/types';

type Next = WorkflowApplyProps['next'];

function next(overrides: Partial<Next> = {}): Next {
    return {
        version: 1,
        noVersionMessage: null,
        departmentName: null,
        nodes: [],
        dependsOnContent: false,
        ...overrides,
    };
}

const person = { id: 3, code: 'u0003', name: '高橋 誠' };

describe('申請できない理由', () => {
    it('処理担当者がいないステップの文は「処理担当者」を使う(処理対象者は画面に出さない)', () => {
        const props = {
            mode: 'new',
            next: next({
                nodes: [{ name: '部長', targets: [], blockedReason: null }],
            }),
        } as WorkflowApplyProps;

        expect(blockerOf(props)).toBe(
            '部長の処理担当者がいないため、申請できません',
        );
    });

    it('版が無ければ版の無い文、再申請は版を見ない', () => {
        const noVersion = next({ version: null, noVersionMessage: '版なし' });

        expect(
            blockerOf({ mode: 'new', next: noVersion } as WorkflowApplyProps),
        ).toBe('版なし');
        expect(
            blockerOf({
                mode: 'reapply',
                next: noVersion,
            } as WorkflowApplyProps),
        ).toBeNull();
    });
});

describe('申請すると誰の未処理に出るかの 1 文', () => {
    it('先頭のステップの処理担当者を示し、ステップが複数ならほかを添える', () => {
        const nodes = [
            { name: '課長', targets: [person], blockedReason: null },
            { name: '部長', targets: [person], blockedReason: null },
        ];

        expect(nextText(next({ nodes }), '申請')).toBe(
            '申請すると、課長の高橋 誠さんほかの未処理に表示されます。',
        );
        expect(nextText(next({ nodes: nodes.slice(0, 1) }), '再申請')).toBe(
            '再申請すると、課長の高橋 誠さんの未処理に表示されます。',
        );
    });

    it('内容で決まるときと、出せる人がいないとき', () => {
        expect(nextText(next({ dependsOnContent: true }), '申請')).toBe(
            '申請すると、内容に応じたステップの未処理に表示されます。',
        );
        expect(nextText(next(), '申請')).toBe('');
    });
});

describe('画面の出し分け', () => {
    it('見出しの下の 1 行は、申請できない理由が無いときだけ次の 1 文を添える', () => {
        expect(headingLineOf('申請', '次の文。', null)).toBe(
            '内容を入力して申請します。次の文。',
        );
        expect(headingLineOf('申請', '次の文。', '理由')).toBe(
            '内容を入力して申請します。',
        );
    });

    it('一時保存のボタンは再申請だけ出さない', () => {
        expect(showsSaveDraft('new')).toBe(true);
        expect(showsSaveDraft('draft')).toBe(true);
        expect(showsSaveDraft('reapply')).toBe(false);
        expect(operationOf('reapply')).toBe('再申請');
        expect(operationOf('new')).toBe('申請');
    });

    it('連絡の誤りは欄の下、申請者の誤りは欄があるときだけ欄の下(ないときはフォームの先頭)', () => {
        expect(isFieldKey('contact.user_ids', false)).toBe(true);
        expect(isFieldKey('contact.message', false)).toBe(true);
        expect(isFieldKey('applicant_user_id', true)).toBe(true);
        expect(isFieldKey('applicant_user_id', false)).toBe(false);
        expect(isFieldKey('principal_user_id', false)).toBe(false);
        expect(isFieldKey('case', true)).toBe(false);
        expect(isFieldKey('values.name', false)).toBe(true);
    });
});
