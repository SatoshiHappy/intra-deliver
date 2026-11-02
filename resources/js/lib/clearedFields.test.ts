import { describe, expect, it } from 'vite-plus/test';
import {
    CLEARED_REASON_TEXTS,
    clearedMarksOf,
    clearedNoticeOf,
    fieldMarksOf,
    shownMarksOf,
    viewersMarkedOf,
} from '@/lib/clearedFields';
import type { RecordFormData } from '@/lib/recordForm';
import type { ClearedField } from '@/types';

// 空にした項目の知らせ(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」と 8 節の文)。

const field = (code: string): ClearedField => ({
    kind: 'field',
    field: code,
    detail: null,
    row: null,
    name: code,
});

const cell = (detail: string, row: number, code: string): ClearedField => ({
    kind: 'cell',
    field: code,
    detail,
    row,
    name: `${detail}の${code}`,
});

const viewers: ClearedField = {
    kind: 'viewers',
    field: null,
    detail: null,
    row: null,
    name: '参照制限の参照者',
};

/** 空にした直後のフォームの値(manager は空、members は残った 1 人、明細の 2 行目の担当は空) */
const cleared: RecordFormData = {
    values: { manager: null, members: [{ id: 3, label: '高橋 誠' }] },
    details: {
        lines: [
            { key: 'a', id: null, values: { owner: '残る' } },
            { key: 'b', id: null, values: { owner: null } },
        ],
    },
};

const marks = clearedMarksOf(
    [field('manager'), field('members'), cell('lines', 1, 'owner'), viewers],
    'copy',
    cleared,
);

describe('空にした欄の印', () => {
    it('親の欄と明細の空にした行のそのセルに印を付け、参照者は欄の印にしない', () => {
        expect(fieldMarksOf(marks)).toEqual({
            fields: { manager: 'copy', members: 'copy' },
            cells: { lines: { b: { owner: 'copy' } } },
        });
    });

    it('欄に値を入れた(空にした直後から変えた)欄の印は消え、件数が減る', () => {
        const typed: RecordFormData = {
            ...cleared,
            values: { ...cleared.values, manager: { id: 9, label: '中村' } },
            details: {
                lines: [
                    cleared.details.lines[0],
                    { key: 'b', id: null, values: { owner: '入れた' } },
                ],
            },
        };

        expect(shownMarksOf([marks], cleared)).toHaveLength(3);
        expect(shownMarksOf([marks], typed).map((mark) => mark.field)).toEqual([
            'members',
        ]);
    });
});

describe('印を出す欄の決め方', () => {
    it('行を並べ替えても、空にした行のセルに印が残る', () => {
        const moved: RecordFormData = {
            ...cleared,
            details: { lines: [...cleared.details.lines].reverse() },
        };

        expect(fieldMarksOf(shownMarksOf([marks], moved)).cells).toEqual({
            lines: { b: { owner: 'copy' } },
        });
    });

    it('同じ欄を 2 つの仕組みで空にしたら、印は 1 つで後から写した方の理由', () => {
        const target = clearedMarksOf(
            [field('manager')],
            'applicationTarget',
            cleared,
        );
        const shown = shownMarksOf([marks, target], cleared);

        expect(shown).toHaveLength(3);
        expect(fieldMarksOf(shown).fields.manager).toBe('applicationTarget');
    });
});

describe('件数の注意と理由の文(画面.md の 8 節)', () => {
    it('件数の注意は印が無ければ出さない', () => {
        expect(clearedNoticeOf(2)).toBe(
            '今は使えない値を空にした項目が 2 件あります。⚠ の付いた欄を入力してください。',
        );
        expect(clearedNoticeOf(0)).toBeNull();
    });

    it('理由の文は空にした仕組みで分ける', () => {
        expect(CLEARED_REASON_TEXTS).toEqual({
            copy: '元の申請の値は、今は使えないため空にしました。',
            applicationTarget:
                '申請対象の今の値は、今は使えないため空にしました。',
            record: '元のレコードの値は、今は使えないため空にしました。',
        });
    });

    it('参照制限の欄の印は、参照者を外していて参照者の欄が開いた時のままの間だけ', () => {
        const opened = [{ id: 1 }];

        expect(viewersMarkedOf([viewers], opened, [{ id: 1 }])).toBe(true);
        expect(viewersMarkedOf([viewers], opened, [{ id: 1 }, { id: 2 }])).toBe(
            false,
        );
        expect(viewersMarkedOf([field('manager')], opened, opened)).toBe(false);
    });
});
