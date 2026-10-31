import { describe, expect, it, vi } from 'vite-plus/test';
import {
    attachmentErrorsOf,
    clearFileErrors,
    fileErrorKeysOf,
    isAttachmentKey,
} from './applyAttachment';

describe('誤りの置き場', () => {
    it('attachments.<添字> はそのファイルの行、attachments と remove_attachment_ids は面の下', () => {
        expect(
            attachmentErrorsOf(
                {
                    'attachments.1': '2 つ目の誤り',
                    attachments: '面の誤り',
                    remove_attachment_ids: '外す誤り',
                },
                2,
                true,
            ),
        ).toEqual({
            rows: { 1: '2 つ目の誤り' },
            surface: ['面の誤り', '外す誤り'],
        });
    });

    it('選んだファイルの数より先の添字は、行が無いので面の下', () => {
        expect(
            attachmentErrorsOf({ 'attachments.2': '誤り' }, 2, true),
        ).toEqual({
            rows: {},
            surface: ['誤り'],
        });
    });

    it('選んだファイルが無いときは、添付の誤りはすべて面の下に出る', () => {
        expect(
            attachmentErrorsOf(
                { attachments: '面の誤り', 'attachments.0': '行の誤り' },
                0,
                true,
            ),
        ).toEqual({ rows: {}, surface: ['面の誤り', '行の誤り'] });
    });

    it('添付のキーでない誤りと値の無い誤りは拾わない', () => {
        expect(
            attachmentErrorsOf(
                { name: '案件名', 'attachments.0': undefined, record: 'x' },
                1,
                true,
            ),
        ).toEqual({ rows: {}, surface: [] });
    });
});

describe('キーの判定', () => {
    it('ファイルの並びに当たる誤りのキーは attachments と attachments.<添字> だけ(remove_attachment_ids は並びに当たらない)', () => {
        expect(
            fileErrorKeysOf({
                attachments: 'a',
                'attachments.1': 'b',
                remove_attachment_ids: 'c',
                name: 'd',
            }),
        ).toEqual(['attachments', 'attachments.1']);
    });

    it('添付のキーの判定', () => {
        for (const key of ['attachments', 'attachments.0', 'attachments.12']) {
            expect(isAttachmentKey(key)).toBe(true);
        }
        expect(isAttachmentKey('remove_attachment_ids')).toBe(true);

        for (const key of [
            'attachment',
            'attachments.x',
            'attachments.0.name',
            'envelope',
        ]) {
            expect(isAttachmentKey(key)).toBe(false);
        }
    });
});

type Form = Parameters<typeof clearFileErrors>[0];

/** フォームの偽物(clearErrors は呼ばれたキーを覚える) */
function fakeForm(errors: Record<string, string>) {
    const clearErrors = vi.fn();

    return { form: { errors, clearErrors } as unknown as Form, clearErrors };
}

describe('ファイルの並びに当たる誤りを消す', () => {
    it('attachments と attachments.<添字> だけを消し、remove_attachment_ids とほかの欄の誤りは残す', () => {
        const { form, clearErrors } = fakeForm({
            attachments: '面の誤り',
            'attachments.1': '行の誤り',
            remove_attachment_ids: '外す誤り',
            name: '案件名の誤り',
        });

        clearFileErrors(form);

        expect(clearErrors).toHaveBeenCalledExactlyOnceWith(
            'attachments',
            'attachments.1',
        );
    });

    it('消すキーが無ければ clearErrors を呼ばない(引数なしは全部の誤りを消すため)', () => {
        const { form, clearErrors } = fakeForm({
            remove_attachment_ids: '外す誤り',
            name: '案件名の誤り',
        });

        clearFileErrors(form);

        expect(clearErrors).not.toHaveBeenCalled();
    });
});
