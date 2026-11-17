import { describe, expect, it } from 'vite-plus/test';
import { normalizeNumberText } from './numberText';

describe('normalizeNumberText', () => {
    it('全角の数字・マイナス・小数点を半角にする', () => {
        expect(normalizeNumberText('５')).toBe('5');
        expect(normalizeNumberText('０１２３４５６７８９')).toBe('0123456789');
        expect(normalizeNumberText('－１２')).toBe('-12');
        expect(normalizeNumberText('１．５')).toBe('1.5');
    });

    it('前後の全角・半角の空白を除く', () => {
        expect(normalizeNumberText(' １２　')).toBe('12');
        expect(normalizeNumberText('　 3 ')).toBe('3');
    });

    it('上付き・丸数字・漢数字・カンマは変えない', () => {
        expect(normalizeNumberText('5²')).toBe('5²');
        expect(normalizeNumberText('①')).toBe('①');
        expect(normalizeNumberText('五')).toBe('五');
        expect(normalizeNumberText('1,000')).toBe('1,000');
        expect(normalizeNumberText('１，０００')).toBe('1，000');
    });

    it('半角の文字と空はそのまま', () => {
        expect(normalizeNumberText('-1.5')).toBe('-1.5');
        expect(normalizeNumberText('')).toBe('');
    });
});
