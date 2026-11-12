import { describe, expect, it } from 'vite-plus/test';
import {
    clearedMarksOf,
    clearedStateOf,
    fieldMarksOf,
    nextClearedStateOf,
    settledMarksOf,
    shownMarksOf,
} from '@/lib/clearedFields';
import type { ClearedMark } from '@/lib/clearedFields';
import type { RecordFormData } from '@/lib/recordForm';
import type { ClearedField } from '@/types';

// 空にした項目の知らせ(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」の表 1・表 2 と 8 節の文)。

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

const withValue = (code: string, value: unknown): RecordFormData => ({
    ...cleared,
    values: { ...cleared.values, [code]: value } as RecordFormData['values'],
});

const marks = clearedMarksOf(
    [field('manager'), field('members'), cell('lines', 1, 'owner'), viewers],
    'copy',
    { record: cleared },
);

const goneOf = (settled: ClearedMark[]) =>
    settled
        .filter((mark) => mark.gone)
        .map((mark) => (mark.kind === 'viewers' ? null : mark.field));

describe('表 1: 開いた時・戻った時(初めの 1 回)', () => {
    it('親の欄と明細の空にした行のそのセルに印を付け、参照者は欄の無い画面では印にしない', () => {
        expect(fieldMarksOf(shownMarksOf([marks]))).toEqual({
            fields: { manager: 'copy', members: 'copy' },
            cells: { lines: { b: { owner: 'copy' } } },
        });
    });

    it('今の値が空にした直後と同じ印は消えていない、違う印(開いた時の申請対象の写しで値が入った)は消えた', () => {
        const state = clearedStateOf([marks], {
            record: withValue('manager', { id: 9, label: '中村' }),
        });

        expect(goneOf(state.groups[0])).toEqual(['manager']);
        expect(shownMarksOf(state.groups)).toHaveLength(2);
    });
});

describe('表 1: 欄の値が変わった時', () => {
    it('値を入れた欄の印は消え、件数が減る', () => {
        const typed = settledMarksOf(marks, {
            record: withValue('members', []),
        });

        expect(goneOf(typed)).toEqual(['members']);
        expect(shownMarksOf([typed])).toHaveLength(2);
    });

    it('値を入れて消えた印は、値が空に戻っても(利用者が消した・申請者の変更で参照の値が消えた)出し直さない', () => {
        const typed = settledMarksOf(marks, {
            record: withValue('manager', { id: 9, label: '中村' }),
        });
        const emptied = settledMarksOf(typed, { record: cleared });

        expect(goneOf(emptied)).toEqual(['manager']);
    });

    it('値が変わらなければ同じ配列のまま(消えていない印は消えていない)', () => {
        expect(settledMarksOf(marks, { record: cleared })).toBe(marks);
    });

    it('行を並べ替えても、空にした行のセルの印は残る', () => {
        const moved = settledMarksOf(marks, {
            record: {
                ...cleared,
                details: { lines: [...cleared.details.lines].reverse() },
            },
        });

        expect(fieldMarksOf(shownMarksOf([moved])).cells).toEqual({
            lines: { b: { owner: 'copy' } },
        });
    });
});

describe('表 1: 明細の行が無くなった時', () => {
    it('行を消すとその行の印は消えて件数から外れ、同じ key の行が後で現れても戻さない', () => {
        const removed = settledMarksOf(marks, {
            record: {
                ...cleared,
                details: { lines: [cleared.details.lines[0]] },
            },
        });
        const back = settledMarksOf(removed, { record: cleared });

        expect(goneOf(removed)).toEqual(['owner']);
        expect(shownMarksOf([back])).toHaveLength(2);
    });
});

describe('表 1: 申請対象を写し直した時', () => {
    const target = clearedMarksOf([field('budget')], 'applicationTarget', {
        record: withValue('budget', null),
    });

    it('前の写しの印を新しい写しの印に置き換え(消えていない で始まる)、元の申請の値の印はそのまま', () => {
        const state = clearedStateOf([marks, target], { record: cleared });
        const recopied = clearedMarksOf(
            [field('manager')],
            'applicationTarget',
            {
                record: cleared,
            },
        );
        const next = nextClearedStateOf(state, [marks, recopied], {
            record: cleared,
        });

        expect(next.groups[0]).toBe(state.groups[0]);
        expect(next.groups[1]).toBe(recopied);
        expect(goneOf(next.groups[1])).toEqual([]);
    });

    it('写し直しで同じ欄を再び空にすると、前に消えた欄にも新しい印が出る', () => {
        const typed = clearedStateOf([marks, []], {
            record: withValue('manager', { id: 9, label: '中村' }),
        });
        const recopied = clearedMarksOf(
            [field('manager')],
            'applicationTarget',
            {
                record: cleared,
            },
        );
        const next = nextClearedStateOf(typed, [marks, recopied], {
            record: cleared,
        });

        expect(fieldMarksOf(shownMarksOf(next.groups)).fields.manager).toBe(
            'applicationTarget',
        );
    });
});
