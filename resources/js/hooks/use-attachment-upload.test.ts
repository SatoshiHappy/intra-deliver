import { describe, expect, it } from 'vite-plus/test';
import { attachmentErrors } from './use-attachment-upload';

describe('attachmentErrors', () => {
    it('添付のキーと持ち主の断りのキー record を欄に出し、ほかのキーは出さない', () => {
        expect(
            attachmentErrors({
                record: '申請済みのため変更できません',
                attachments: 'ファイルを選択してください',
                'attachments.0': 'このファイルの形式は追加できません',
                body: 'コメントを入力してください',
            }),
        ).toEqual([
            { key: 'record', message: '申請済みのため変更できません' },
            { key: 'attachments', message: 'ファイルを選択してください' },
            {
                key: 'attachments.0',
                message: 'このファイルの形式は追加できません',
            },
        ]);
    });
});
