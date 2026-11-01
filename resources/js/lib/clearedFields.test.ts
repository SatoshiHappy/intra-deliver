import { describe, expect, it } from 'vitest';
import { clearedFieldsLineOf } from '@/lib/clearedFields';

describe('clearedFieldsLineOf', () => {
    it('空にした項目の名前を「、」で並べた 1 行にする', () => {
        expect(
            clearedFieldsLineOf('元のレコード', ['担当者', '明細の品目']),
        ).toBe(
            '元のレコードの値のうち、担当者、明細の品目は、現在使用できない値を外しました。',
        );
    });

    it('空にした項目が無ければ 1 行を出さない', () => {
        expect(clearedFieldsLineOf('申請対象', [])).toBeNull();
    });
});
