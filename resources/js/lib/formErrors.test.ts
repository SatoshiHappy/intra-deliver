import { describe, expect, it, vi } from 'vite-plus/test';
import { clearRowErrors, clearServerErrors } from './formErrors';

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
