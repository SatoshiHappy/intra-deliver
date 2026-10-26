import { describe, expect, it } from 'vite-plus/test';
import { applyPayloadOf } from './applyForm';
import type { ApplyForm } from './applyForm';
import { CONTACT_MESSAGE_MAX, CONTACT_USER_MAX } from './caseActionForm';
import type { WorkflowApplyProps } from '@/types';

const module = {
    code: 'm',
    name: 'm',
    description: null,
    fields: [],
    rules: [],
};

function props(
    overrides: Partial<WorkflowApplyProps> = {},
): WorkflowApplyProps {
    return {
        mode: 'new',
        case: null,
        module,
        ...overrides,
    } as WorkflowApplyProps;
}

function data(overrides: Partial<ApplyForm> = {}): ApplyForm {
    return {
        name: '名前',
        comment: 'コメント',
        base_date: '2026-10-13',
        applicant: null,
        record: { values: {}, details: {} },
        contact_users: [],
        contact_message: '',
        ...overrides,
    };
}

const person = { id: 3, code: 'u0003', name: '高橋 誠' };
const reapply = props({
    mode: 'reapply',
    case: { id: 5, number: 'WF-000005', revision: 3 },
});
const draft = props({
    mode: 'draft',
    case: { id: 5, number: null, revision: 1 },
});

describe('申請者の送り方', () => {
    it('新規の申請・新規の一時保存は applicant_user_id(自分なら null、代理元ならその id)', () => {
        for (const kind of ['apply', 'saveDraft'] as const) {
            expect(applyPayloadOf(props(), data(), kind)).toHaveProperty(
                'applicant_user_id',
                null,
            );
            expect(
                applyPayloadOf(props(), data({ applicant: 10 }), kind),
            ).toHaveProperty('applicant_user_id', 10);
        }
    });

    it('再申請は principal_user_id に申請者を送る(applicant_user_id は送らない)', () => {
        const payload = applyPayloadOf(
            reapply,
            data({ applicant: 10 }),
            'apply',
        );

        expect(payload).toHaveProperty('principal_user_id', 10);
        expect(payload).not.toHaveProperty('applicant_user_id');
        expect(payload).not.toHaveProperty('base_date');
        expect(payload).toHaveProperty('revision', 3);
        expect(applyPayloadOf(reapply, data(), 'apply')).toHaveProperty(
            'principal_user_id',
            null,
        );
    });

    it('一時保存の上書き・一時保存からの申請は申請者を送らない', () => {
        for (const kind of ['apply', 'saveDraft'] as const) {
            const payload = applyPayloadOf(
                draft,
                data({ applicant: 10 }),
                kind,
            );

            expect(payload).not.toHaveProperty('applicant_user_id');
            expect(payload).not.toHaveProperty('principal_user_id');
            expect(payload).toHaveProperty('base_date', '2026-10-13');
        }
    });
});

describe('連絡の送り方', () => {
    const contacted = data({
        contact_users: [person],
        contact_message: 'お願いします',
    });

    it('申請と再申請(kind = apply)だけ contact を送る', () => {
        const expected = { user_ids: [3], message: 'お願いします' };

        expect(applyPayloadOf(props(), contacted, 'apply')).toHaveProperty(
            'contact',
            expected,
        );
        expect(applyPayloadOf(reapply, contacted, 'apply')).toHaveProperty(
            'contact',
            expected,
        );
        expect(applyPayloadOf(draft, contacted, 'apply')).toHaveProperty(
            'contact',
            expected,
        );
    });

    it('一時保存の操作では送らない', () => {
        expect(
            applyPayloadOf(props(), contacted, 'saveDraft'),
        ).not.toHaveProperty('contact');
    });

    it('人もひと言も空なら送らない。ひと言だけなら人を空で送る(422 は業務処理が返す)', () => {
        expect(applyPayloadOf(props(), data(), 'apply')).not.toHaveProperty(
            'contact',
        );
        expect(
            applyPayloadOf(props(), data({ contact_message: '連絡' }), 'apply'),
        ).toHaveProperty('contact', { user_ids: [], message: '連絡' });
    });

    it('連絡する人は 10 人まで、ひと言は 1000 文字まで(処理のダイアログと同じ上限)', () => {
        expect(CONTACT_USER_MAX).toBe(10);
        expect(CONTACT_MESSAGE_MAX).toBe(1000);
    });
});
