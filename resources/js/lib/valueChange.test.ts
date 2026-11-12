import { describe, expect, it } from 'vite-plus/test';
import {
    UNSET_VALUE,
    VALUE_AFTER_CLASS,
    VALUE_BEFORE_CLASS,
    multilineTextOf,
} from '@/lib/valueChange';

// 値の変化の見せ方(docs の 設計/画面の意匠/README.md の採った方針 15)。空の判定は recordValues の isEmptyRecordValue のテスト。

describe('前と後の見分け', () => {
    it('前は薄い字と取り消し線、後は太字と主の色で、取り消し線を付けない', () => {
        const before = VALUE_BEFORE_CLASS.split(' ');
        const after = VALUE_AFTER_CLASS.split(' ');

        expect(before).toEqual(
            expect.arrayContaining(['text-muted-foreground', 'line-through']),
        );
        expect(after).toEqual(
            expect.arrayContaining(['font-bold', 'text-primary']),
        );
        expect(after).not.toContain('line-through');
    });
});

describe('複数行の前・後の文', () => {
    it('文はそのまま、空は「未設定」', () => {
        expect(multilineTextOf('1 行目\n2 行目')).toBe('1 行目\n2 行目');
        expect(multilineTextOf('')).toBe(UNSET_VALUE);
        expect(multilineTextOf(null)).toBe(UNSET_VALUE);
    });
});
