import { describe, expect, it } from 'vite-plus/test';
import { moduleDescriptionHelp } from './moduleDescription';

describe('moduleDescriptionHelp', () => {
    it('説明があればその文を「?」に入れる', () => {
        expect(moduleDescriptionHelp('社内のプロジェクト')).toBe(
            '社内のプロジェクト',
        );
    });

    it('説明が無い・空なら「?」を出さない', () => {
        expect(moduleDescriptionHelp(null)).toBeNull();
        expect(moduleDescriptionHelp('')).toBeNull();
    });
});
