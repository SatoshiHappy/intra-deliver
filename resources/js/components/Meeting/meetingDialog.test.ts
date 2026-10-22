import { describe, expect, it } from 'vite-plus/test';
import {
    acceptsOpenChange,
    booleanProp,
    limitSizeText,
    nullableStringProp,
    numberProp,
} from './meetingDialog';

describe('numberProp', () => {
    it('数の値を返し、無い・数でない値は約束の誤りとして投げる', () => {
        expect(numberProp({ textMaxBytes: 1_048_576 }, 'textMaxBytes')).toBe(
            1_048_576,
        );
        expect(() => numberProp({}, 'textMaxBytes')).toThrow('textMaxBytes');
        expect(() => numberProp({ textMaxBytes: '1' }, 'textMaxBytes')).toThrow(
            'textMaxBytes',
        );
    });
});

describe('booleanProp', () => {
    it('真偽の値を返し、無い・真偽でない値は約束の誤りとして投げる', () => {
        expect(booleanProp({ hasTranscript: false }, 'hasTranscript')).toBe(
            false,
        );
        expect(booleanProp({ hasTranscript: true }, 'hasTranscript')).toBe(
            true,
        );
        expect(() => booleanProp({}, 'hasTranscript')).toThrow('hasTranscript');
        expect(() =>
            booleanProp({ hasTranscript: 1 }, 'hasTranscript'),
        ).toThrow('hasTranscript');
    });
});

describe('nullableStringProp', () => {
    it('文と null を返し、無い・どちらでもない値は約束の誤りとして投げる', () => {
        const key = 'audioBlockedMessage';

        expect(nullableStringProp({ [key]: '音声は選べません。' }, key)).toBe(
            '音声は選べません。',
        );
        expect(nullableStringProp({ [key]: null }, key)).toBeNull();
        expect(() => nullableStringProp({}, key)).toThrow(key);
        expect(() => nullableStringProp({ [key]: false }, key)).toThrow(key);
    });
});

describe('limitSizeText', () => {
    it('割り切れる最大の単位で書き、どれでも割り切れなければバイト', () => {
        expect(limitSizeText(1_048_576)).toBe('1MB');
        expect(limitSizeText(99_614_720)).toBe('95MB');
        expect(limitSizeText(2_147_483_648)).toBe('2GB');
        expect(limitSizeText(2_048)).toBe('2KB');
        expect(limitSizeText(1_536)).toBe('1536B');
    });
});

describe('acceptsOpenChange', () => {
    it('送っている間の「閉じる」だけを捨てる', () => {
        expect(acceptsOpenChange(false, true)).toBe(false);
        expect(acceptsOpenChange(false, false)).toBe(true);
        expect(acceptsOpenChange(true, true)).toBe(true);
        expect(acceptsOpenChange(true, false)).toBe(true);
    });
});
