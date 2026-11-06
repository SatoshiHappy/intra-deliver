import { describe, expect, it } from 'vite-plus/test';
import { fillHeightOf, hasContentBelow } from './scrollEdges';

describe('hasContentBelow(帯から上へ影を出すか = 画面の意匠の方針 14)', () => {
    it('見えている範囲の下に中身が続けば真、末尾まで進めば偽', () => {
        expect(hasContentBelow(0, 400, 900)).toBe(true);
        expect(hasContentBelow(400, 400, 900)).toBe(true);
        expect(hasContentBelow(500, 400, 900)).toBe(false);
    });

    it('中身が欄に収まる(スクロールしない)なら偽', () => {
        expect(hasContentBelow(0, 400, 400)).toBe(false);
        expect(hasContentBelow(0, 400, 300)).toBe(false);
    });

    it('拡大率による 1px 以内の差は末尾とみなし、1px を超えれば続くとみなす', () => {
        // 残りが 0.9px・1px(境界)は末尾、1.1px は続く
        expect(hasContentBelow(499.1, 400, 900)).toBe(false);
        expect(hasContentBelow(499, 400, 900)).toBe(false);
        expect(hasContentBelow(498.9, 400, 900)).toBe(true);
    });
});

describe('fillHeightOf(画面の高さに合わせた高さ)', () => {
    it('画面の高さから要素の上端と下の余白を引く', () => {
        expect(fillHeightOf(900, 230, 16, 420)).toBe(654);
    });

    it('最小の高さより低くしない(境界の値はそのまま)', () => {
        expect(fillHeightOf(666, 230, 16, 420)).toBe(420);
        expect(fillHeightOf(665, 230, 16, 420)).toBe(420);
        expect(fillHeightOf(667, 230, 16, 420)).toBe(421);
    });
});
