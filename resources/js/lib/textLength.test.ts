import { describe, expect, it } from 'vite-plus/test';
import { countCharacters } from './textLength';

describe('countCharacters', () => {
    it('サロゲートペアの文字を 1 文字と数える', () => {
        expect(countCharacters('𠮷野家')).toBe(3);
    });

    it('空の文字列は 0 文字', () => {
        expect(countCharacters('')).toBe(0);
    });
});
