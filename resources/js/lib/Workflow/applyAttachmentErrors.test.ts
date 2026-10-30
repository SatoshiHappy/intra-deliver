import { describe, expect, it } from 'vite-plus/test';
import {
    attachmentErrorsOf,
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
