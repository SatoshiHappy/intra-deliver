import { describe, expect, it, vi } from 'vite-plus/test';
import { clearFileErrors } from './use-apply-form';

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
