import { describe, expect, it, vi } from 'vite-plus/test';
import { applyReloaderOf } from './use-apply-reload';
import type { ApplyReloadForm, ApplyReloadSend } from './use-apply-reload';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import type { RecordModuleDefinition } from '@/types';

type SendOptions = Parameters<ApplyReloadSend>[0];

const self = { id: 9, name: '吉田 達也', department: '総務部', isSelf: true };
const yamada = { id: 10, name: '山田 恵', department: '経理部', isSelf: false };

const module: RecordModuleDefinition = {
    code: 'm',
    name: 'm',
    description: null,
    fields: [],
    rules: [],
};

/** 送った要求を覚える偽物の送り先と、取消の関数 */
function fakeSend() {
    const sent: SendOptions[] = [];
    const cancels: ReturnType<typeof vi.fn>[] = [];
    const send: ApplyReloadSend = (options) => {
        const cancel = vi.fn();
        sent.push(options);
        cancels.push(cancel);
        options.onCancelToken({ cancel });
    };

    return { send, sent, cancels };
}

/** フォームの偽物(setData は値を書き換える) */
function fakeForm(data: Partial<ApplyForm>): ApplyReloadForm {
    const form = {
        data: {
            name: '',
            comment: '',
            base_date: '2026-10-13',
            applicant: null,
            record: { values: {}, details: {} },
            contact_users: [],
            contact_message: '',
            ...data,
        } as ApplyForm,
        setData: ((key: unknown, value?: unknown) => {
            form.data =
                typeof key === 'function'
                    ? key(form.data)
                    : { ...form.data, [key as string]: value };
        }) as ApplyReloadForm['setData'],
    };

    return form;
}

const scope = { openedApplicant: null, fromTarget: false };

describe('待っている要求が終わったら消す', () => {
    it('申請者の読み直しが終わった後に申請基準日を空にしても送り直さない', () => {
        const { send, sent } = fakeSend();
        const reloader = applyReloaderOf(send, scope);
        const form = fakeForm({});

        reloader.changeApplicant(form, module, 10);
        sent[0].onFinish();
        reloader.changeBaseDate(form, '');

        expect(sent).toHaveLength(1);
        expect(form.data.base_date).toBe('');
    });

    it('申請者の読み直しが終わる前に申請基準日を空にしたら、取り消して申請者の props を送り直す', () => {
        const { send, sent, cancels } = fakeSend();
        const reloader = applyReloaderOf(send, scope);
        const form = fakeForm({});

        reloader.changeApplicant(form, module, 10);
        reloader.changeBaseDate(form, '');

        expect(cancels[0]).toHaveBeenCalledOnce();
        expect(sent).toHaveLength(2);
        expect(sent[1].data).toEqual({ applicant: 10 });
        expect(sent[1].only).toContain('applicant');
    });
});

describe('待っている要求が終わったら消す(取り消した要求)', () => {
    it('取り消された古い要求の終わりは、後から送った要求を待ちから消さない', () => {
        const { send, sent } = fakeSend();
        const reloader = applyReloaderOf(send, scope);
        const form = fakeForm({});

        reloader.changeApplicant(form, module, 10);
        reloader.changeBaseDate(form, '2026-10-20');
        sent[0].onFinish();
        reloader.changeBaseDate(form, '');

        expect(sent).toHaveLength(3);
        expect(sent[2].only).toContain('applicant');
    });
});

describe('申請者の読み直しの応答をフォームへ写す', () => {
    it('返った申請者と申請基準日を写す', () => {
        const { send, sent } = fakeSend();
        const reloader = applyReloaderOf(send, scope);
        const form = fakeForm({});

        reloader.changeApplicant(form, module, 10);
        sent[0].onSuccess({
            props: {
                applicants: [self, yamada],
                applicant: null,
                baseDate: '2026-10-01',
            },
        });

        expect(form.data.applicant).toBeNull();
        expect(form.data.base_date).toBe('2026-10-01');
        expect(sent).toHaveLength(1);
    });

    it('申請基準日だけの読み直しは写さない', () => {
        const { send, sent } = fakeSend();
        const reloader = applyReloaderOf(send, scope);
        const form = fakeForm({ applicant: 10 });

        reloader.changeBaseDate(form, '2026-10-20');
        sent[0].onSuccess({
            props: {
                applicants: [self],
                applicant: null,
                baseDate: '2026-10-01',
            },
        });

        expect(form.data.applicant).toBe(10);
        expect(form.data.base_date).toBe('2026-10-20');
    });
});

describe('古い応答', () => {
    it('応答の後に次の要求を送ったら、古い応答はフォームへ写さず、送り直さない', () => {
        const { send, sent } = fakeSend();
        const reloader = applyReloaderOf(send, scope);
        const form = fakeForm({});

        reloader.changeApplicant(form, module, 10);
        reloader.changeApplicant(form, module, null);
        sent[0].onSuccess({
            props: {
                applicants: [self],
                applicant: 10,
                baseDate: '2026-10-01',
            },
        });

        expect(form.data.applicant).toBeNull();
        expect(form.data.base_date).toBe('2026-10-13');
        expect(sent).toHaveLength(2);
    });
});

describe('申請者の読み直しの応答が選択肢に無い申請者', () => {
    it('返った申請者が選択肢に無ければ、選択肢を足して読み直す', () => {
        const { send, sent, cancels } = fakeSend();
        const reloader = applyReloaderOf(send, scope);
        const form = fakeForm({});

        reloader.changeApplicant(form, module, 10);
        sent[0].onSuccess({
            props: {
                applicants: [self],
                applicant: 10,
                baseDate: '2026-10-13',
            },
        });

        expect(cancels[0]).not.toHaveBeenCalled();
        expect(sent).toHaveLength(2);
        expect(sent[1].only).toContain('applicants');
        expect(sent[1].data).toEqual({
            applicant: 10,
            base_date: '2026-10-13',
        });
    });
});

describe('サーバーが別の申請者を選び直したとき', () => {
    const loaded = (applicant: number | null) => ({
        props: {
            applicants: [self, yamada],
            applicant,
            baseDate: '2026-10-13',
        },
    });

    it('要求した申請者と違う申請者が返ったら、申請者を変えたのと同じに知らせる', () => {
        const { send, sent } = fakeSend();
        const onReselected = vi.fn();
        const reloader = applyReloaderOf(send, scope, onReselected);

        reloader.changeApplicant(fakeForm({}), module, 10);
        sent[0].onSuccess(loaded(null));

        expect(onReselected).toHaveBeenCalledOnce();
    });

    it('要求した申請者のままなら(自分を要求して自分が返った場合を含む)知らせない', () => {
        const { send, sent } = fakeSend();
        const onReselected = vi.fn();
        const reloader = applyReloaderOf(send, scope, onReselected);
        const form = fakeForm({ applicant: 10 });

        reloader.changeApplicant(form, module, null);
        sent[0].onSuccess(loaded(null));
        reloader.changeApplicant(form, module, 10);
        sent[1].onSuccess(loaded(10));

        expect(onReselected).not.toHaveBeenCalled();
    });
});
