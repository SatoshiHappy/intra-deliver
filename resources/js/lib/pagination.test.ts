import { describe, expect, it } from 'vite-plus/test';
import { pageItems } from './pagination';

describe('pageItems', () => {
    it('前後 2 ページの中なら全部の番号を出す', () => {
        expect(pageItems(3, 5)).toEqual([1, 2, 3, 4, 5]);
    });

    it('飛んだところは「…」(null)を 1 つだけ置き、最初と最後はいつも出す', () => {
        expect(pageItems(6, 12)).toEqual([1, null, 4, 5, 6, 7, 8, null, 12]);
    });

    it('先頭のページでは後ろだけが飛ぶ', () => {
        expect(pageItems(1, 10)).toEqual([1, 2, 3, null, 10]);
    });

    it('最後のページでは前だけが飛ぶ', () => {
        expect(pageItems(10, 10)).toEqual([1, null, 8, 9, 10]);
    });

    it('件数 0 の一覧(最後のページ 1)でも 1 を出す', () => {
        expect(pageItems(1, 1)).toEqual([1]);
    });
});
