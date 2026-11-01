import { describe, expect, it, vi } from 'vite-plus/test';
import { applyReloaderOf } from './use-apply-reload';
import type { ApplyReloadForm, ApplyReloadSend } from './use-apply-reload';
import type { ApplyReloadStatus } from '@/lib/Workflow/applyReload';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import type { RecordModuleDefinition } from '@/types';

// 申請者・申請基準日の読み直しの間と失敗(docs の 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 5 節の反映 =
// 申請の周辺/既存の設計への反映/3aと3b-1.md、申請の周辺/画面.md の 5 節・8 節): 読み直しの間は押せない(変えた直後も戻った直後も)、
// 失敗はトーストと添え書きで欄は変えた値のまま押せる状態に戻る、失敗の後は同じ値を選び直しても読み直し、古い props をすべて読み直す要求が通れば添え書きが消える。

type SendOptions = Parameters<ApplyReloadSend>[0];

const module: RecordModuleDefinition = {
    code: 'm',
    name: 'm',
    description: null,
    fields: [
        {
            code: 'ref',
            name: 'ref',
            type: 'record',
            isMultiple: false,
        } as RecordModuleDefinition['fields'][number],
    ],
    rules: [],
};

function setup() {
    const sent: SendOptions[] = [];
    const statuses: ApplyReloadStatus[] = [];
    const onFailed = vi.fn();
    const send: ApplyReloadSend = (options) => {
        sent.push(options);
        options.onCancelToken({ cancel: vi.fn() });
    };
    const reloader = applyReloaderOf(send, {
        onStatus: (status) => statuses.push(status),
        onFailed,
    });
    const form = {
        data: {
            name: '',
            comment: '',
            base_date: '2026-10-13',
            applicant: null,
            record: { values: { ref: { id: 1, label: 'A' } }, details: {} },
            contact_users: [],
            contact_message: '',
        } as unknown as ApplyForm,
        setData: ((key: unknown, value?: unknown) => {
            form.data =
                typeof key === 'function'
                    ? key(form.data)
                    : { ...form.data, [key as string]: value };
        }) as ApplyReloadForm['setData'],
    };
    const last = () => statuses.at(-1);

    return { sent, statuses, onFailed, reloader, form, last };
}

const loaded = (applicant: number | null, baseDate = '2026-10-13') => ({
    props: { applicants: [{ id: 10 }], applicant, baseDate },
});

describe('読み直しの間', () => {
    it('申請基準日を変えた直後から終わるまで busy(押せない)、終われば押せる', () => {
        const { sent, reloader, form, last } = setup();

        reloader.changeBaseDate(form, '2026-10-20');
        expect(last()).toEqual({ busy: true, stale: false });

        sent[0].onSuccess(loaded(null, '2026-10-20'));
        sent[0].onFinish();
        expect(last()).toEqual({ busy: false, stale: false });
    });
});

describe('読み直しの失敗', () => {
    it('応答の誤り・通信の失敗: トーストを 1 回、添え書き、欄は変えた値のまま、押せる状態に戻る(Inertia の既定の出し方は止める)', () => {
        for (const kind of ['onHttpException', 'onNetworkError'] as const) {
            const { sent, onFailed, reloader, form, last } = setup();

            reloader.changeBaseDate(form, '2026-10-20');
            expect(sent[0][kind]()).toBe(false);
            sent[0].onFinish();

            expect(onFailed).toHaveBeenCalledOnce();
            expect(last()).toEqual({ busy: false, stale: true });
            expect(form.data.base_date).toBe('2026-10-20');
        }
    });

    it('取り消した要求の失敗は数えない', () => {
        const { sent, onFailed, reloader, form } = setup();

        reloader.changeBaseDate(form, '2026-10-20');
        reloader.changeBaseDate(form, '2026-10-21');
        sent[0].onHttpException();

        expect(onFailed).not.toHaveBeenCalled();
    });
});

describe('読み直しの失敗の後', () => {
    it('失敗の後に同じ申請者を選び直すと読み直し(参照の値は消さない)、通れば添え書きが消える', () => {
        const { sent, reloader, form, last } = setup();

        reloader.changeApplicant(form, module, 10);
        sent[0].onHttpException();
        sent[0].onFinish();
        form.setData('record', {
            values: { ref: { id: 2, label: 'B' } },
            details: {},
        });
        reloader.changeApplicant(form, module, 10);

        expect(sent).toHaveLength(2);
        expect(form.data.record.values.ref).toEqual({ id: 2, label: 'B' });
        sent[1].onSuccess(loaded(10));
        sent[1].onFinish();
        expect(last()).toEqual({ busy: false, stale: false });
    });

    it('失敗していなければ、同じ申請者を選び直しても読み直さない', () => {
        const { sent, reloader, form } = setup();

        reloader.changeApplicant(form, module, null);

        expect(sent).toHaveLength(0);
    });
});

/** 申請者の組の読み直しか(申請者に依る props を読み直す) */
const byApplicant = ({ only }: SendOptions) => only.includes('applicant');

describe('申請者の読み直しの失敗の後の申請基準日の変更', () => {
    it('申請者の組で送り、通れば添え書きが消える', () => {
        const { sent, reloader, form, last } = setup();

        reloader.changeApplicant(form, module, 10);
        sent[0].onHttpException();
        sent[0].onFinish();
        reloader.changeBaseDate(form, '2026-10-20');

        expect(byApplicant(sent[1])).toBe(true);
        expect(sent[1].data).toEqual({
            applicant: 10,
            base_date: '2026-10-20',
        });
        sent[1].onSuccess(loaded(10, '2026-10-20'));
        sent[1].onFinish();
        expect(last()).toEqual({ busy: false, stale: false });
    });

    it('申請基準日を空にしても、申請者の組で(申請基準日を外して)送る', () => {
        const { sent, reloader, form } = setup();

        reloader.changeApplicant(form, module, 10);
        sent[0].onNetworkError();
        sent[0].onFinish();
        reloader.changeBaseDate(form, '');

        expect(sent).toHaveLength(2);
        expect(byApplicant(sent[1])).toBe(true);
        expect(sent[1].data).toEqual({ applicant: 10 });
    });
});

describe('申請基準日の読み直しの失敗の後', () => {
    it('続けて申請者の読み直しも失敗したら、申請基準日の変更は申請者の組で送る', () => {
        const { sent, reloader, form, last } = setup();

        reloader.changeBaseDate(form, '2026-10-20');
        sent[0].onHttpException();
        sent[0].onFinish();
        reloader.changeApplicant(form, module, 10);
        sent[1].onHttpException();
        sent[1].onFinish();
        reloader.changeBaseDate(form, '2026-10-21');

        expect(byApplicant(sent[2])).toBe(true);
        expect(last()).toEqual({ busy: true, stale: true });
    });

    it('申請者の変更の読み直しが通れば添え書きが消える', () => {
        const { sent, reloader, form, last } = setup();

        reloader.changeBaseDate(form, '2026-10-20');
        sent[0].onHttpException();
        sent[0].onFinish();
        reloader.changeApplicant(form, module, 10);
        sent[1].onSuccess(loaded(10, '2026-10-20'));
        sent[1].onFinish();

        expect(last()).toEqual({ busy: false, stale: false });
    });
});

describe('戻る・進むで来た時の読み直し', () => {
    it('申請者が今の props と違えば申請者に依る props を、申請基準日だけが違えば next を読み直し、同じなら読み直さない', () => {
        const cases = [
            [{ applicant: 10, base_date: '2026-10-13' }, 'applicant'],
            [{ applicant: null, base_date: '2026-10-20' }, 'next'],
            [{ applicant: null, base_date: '2026-10-13' }, null],
            [{ applicant: null, base_date: '' }, null],
        ] as const;

        for (const [data, prop] of cases) {
            const { sent, reloader, form, last } = setup();
            form.setData((current) => ({ ...current, ...data }));

            reloader.reloadIfStale(form, {
                applicant: null,
                baseDate: '2026-10-13',
            });

            expect(sent.map(({ only }) => only.includes(prop ?? ''))).toEqual(
                prop === null ? [] : [true],
            );
            expect(last()?.busy ?? false).toBe(prop !== null);
        }
    });

    it('戻った直後の読み直しの失敗も、トーストと添え書きで押せる状態に戻る', () => {
        const { sent, onFailed, reloader, form, last } = setup();
        form.setData('base_date', '2026-10-20');

        reloader.reloadIfStale(form, {
            applicant: null,
            baseDate: '2026-10-13',
        });
        sent[0].onNetworkError();
        sent[0].onFinish();

        expect(onFailed).toHaveBeenCalledOnce();
        expect(last()).toEqual({ busy: false, stale: true });
    });
});
