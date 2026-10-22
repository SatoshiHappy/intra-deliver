import { describe, expect, it, vi } from 'vite-plus/test';
import {
    clearRowErrors,
    clearServerErrors,
    unshownErrorKeys,
} from './formErrors';

describe('clearServerErrors', () => {
    it('キーがあれば、そのキーで clearErrors を呼ぶ', () => {
        const clearErrors = vi.fn();

        clearServerErrors({ clearErrors }, ['options.0.code']);

        expect(clearErrors).toHaveBeenCalledWith('options.0.code');
    });

    it('キーが無ければ clearErrors を呼ばない(引数なしは全部の誤りを消すため)', () => {
        const clearErrors = vi.fn();

        clearServerErrors({ clearErrors }, []);

        expect(clearErrors).not.toHaveBeenCalled();
    });
});

describe('clearRowErrors', () => {
    it('prefix で始まるキーだけを消す', () => {
        const clearErrors = vi.fn();

        clearRowErrors(
            {
                errors: {
                    options: '選択肢は 1 つ以上',
                    'options.0.code': 'コードは必須',
                    'options.1.name': '名前は必須',
                    name: '名前は必須',
                },
                clearErrors,
            },
            'options.',
        );

        expect(clearErrors).toHaveBeenCalledTimes(1);
        expect(clearErrors).toHaveBeenCalledWith(
            'options.0.code',
            'options.1.name',
        );
    });

    it('prefix に合うキーが無ければ clearErrors を呼ばない', () => {
        const clearErrors = vi.fn();

        clearRowErrors(
            { errors: { name: '名前は必須' }, clearErrors },
            'options.',
        );

        expect(clearErrors).not.toHaveBeenCalled();
    });
});

describe('unshownErrorKeys', () => {
    it('管理の画面: 欄の下に出すキー(name)のほかを、キーの順に返す', () => {
        expect(
            unshownErrorKeys(
                { version: '古い版です', name: '名前は必須', category: '無い' },
                ['name'],
            ),
        ).toEqual(['category', 'version']);
    });

    it('業務の登録・編集のフォーム: 欄の下に出さない誤りが無ければ空', () => {
        expect(unshownErrorKeys({}, ['values.title'])).toEqual([]);
        expect(
            unshownErrorKeys({ 'values.title': '必須' }, ['values.title']),
        ).toEqual([]);
    });

    it('欄の下に出さない値のキー(会議のダイアログに無い項目)も欄の上に回す', () => {
        expect(
            unshownErrorKeys(
                {
                    'values.purge_due_on': '削除予定日：必須',
                    ai: '上限に達しました',
                    file: '選んでください',
                },
                ['file', 'values.held_at'],
            ),
        ).toEqual(['ai', 'values.purge_due_on']);
    });
});
