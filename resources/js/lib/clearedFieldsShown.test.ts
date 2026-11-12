import { describe, expect, it } from 'vite-plus/test';
import {
    CLEARED_REASON_TEXTS,
    clearedMarksOf,
    clearedNoticeOf,
    clearedStateOf,
    fieldMarksOf,
    nextClearedStateOf,
    settledMarksOf,
    shownMarksOf,
} from '@/lib/clearedFields';
import type { ClearedValues } from '@/lib/clearedFields';
import type { ClearedField } from '@/types';

// 空にした印を出すか・数えるか(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」の表 2 と、表 1 の
// 参照制限のチェックの行。文は 8 節)。業務データの画面の複製は参照者の欄を持つ。

const manager: ClearedField = {
    kind: 'field',
    field: 'manager',
    detail: null,
    row: null,
    name: '責任者',
};

const viewers: ClearedField = {
    kind: 'viewers',
    field: null,
    detail: null,
    row: null,
    name: '参照制限の参照者',
};

/** 業務データの画面の複製の開いた時(参照者は残った 1 人) */
const opened: ClearedValues = {
    record: { values: { manager: null }, details: {} },
    viewers: [{ type: 'user', id: 1 }],
};

const marks = clearedMarksOf([manager, viewers], 'record', opened);

describe('表 2: 項目・明細のセルと、業務データの画面の複製の参照者', () => {
    it('消えていない項目の印は出して数え、参照者の印はチェックが付いている間だけ参照制限の欄のそばに出して数える', () => {
        expect(shownMarksOf([marks], true)).toHaveLength(2);
        expect(fieldMarksOf(shownMarksOf([marks], true)).viewers).toBe(
            'record',
        );
        expect(shownMarksOf([marks], false)).toHaveLength(1);
    });

    it('チェックを外して付け直すと、参照者の印は消えていないので戻る(表 1: チェックでは消えない)', () => {
        const state = clearedStateOf([marks], opened);
        const toggled = nextClearedStateOf(state, [marks], { ...opened });

        expect(toggled).toBe(state);
        expect(shownMarksOf(toggled.groups, true)).toHaveLength(2);
    });

    it('参照者を足して消しても(開いた時の参照者に戻っても)印は出ない', () => {
        const added = settledMarksOf(marks, {
            ...opened,
            viewers: [...(opened.viewers ?? []), { type: 'user', id: 2 }],
        });
        const removed = settledMarksOf(added, opened);

        expect(fieldMarksOf(shownMarksOf([removed], true)).viewers).toBe(
            undefined,
        );
    });

    it('申請の画面(参照者の欄が無い)では参照者を印にしない(見出しの下の 1 行 = applyCleared)', () => {
        expect(
            clearedMarksOf([manager, viewers], 'copy', {
                record: opened.record,
            }).map((mark) => mark.kind),
        ).toEqual(['field']);
    });

    it('同じ欄に 2 つの仕組みの印があれば、後の組の理由の 1 つにまとめて件数は 1', () => {
        const target = clearedMarksOf([manager], 'applicationTarget', opened);
        const shown = shownMarksOf([marks, target]);

        expect(shown).toHaveLength(1);
        expect(fieldMarksOf(shown).fields.manager).toBe('applicationTarget');
    });
});

describe('件数の注意と理由の文(画面.md の 8 節)', () => {
    it('件数は印の数。0 なら注意を出さない', () => {
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
});
