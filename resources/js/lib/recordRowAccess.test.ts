import { describe, expect, it } from 'vite-plus/test';
import { unpickedOptions } from './pickedReference';
import { fieldErrorOf, referenceItemErrors } from './recordFormErrors';
import {
    isRowAccessKey,
    restrictedText,
    rowAccessDraft,
    rowAccessPayload,
    VIEWERS_FIELD,
    VIEWERS_KEY,
} from './recordRowAccess';

const user = (id: number, label: string | null = `利用者${id}`) => ({
    type: 'user' as const,
    id,
    code: `u${id}`,
    label,
    affiliation: '営業部',
});

const department = (id: number) => ({
    type: 'department' as const,
    id,
    code: `d${id}`,
    label: `組織${id}`,
    affiliation: null,
});

describe('rowAccessDraft', () => {
    it('チェックと参照者を欄の値にする(参照者は相手の種類・表示名・所属を持つ選んだ相手。並びはそのまま)', () => {
        expect(
            rowAccessDraft({
                restricted: true,
                viewers: [department(3), user(3)],
            }),
        ).toEqual({
            restricted: true,
            viewers: [
                {
                    type: 'department',
                    id: 3,
                    label: '組織3',
                    affiliation: null,
                },
                {
                    type: 'user',
                    id: 3,
                    label: '利用者3',
                    affiliation: '営業部',
                },
            ],
        });
    });

    it('登録の初期値(既定と空)はチェックだけ、参照者は空', () => {
        expect(rowAccessDraft({ restricted: false, viewers: [] })).toEqual({
            restricted: false,
            viewers: [],
        });
    });
});

describe('rowAccessPayload', () => {
    const draft = rowAccessDraft({
        restricted: true,
        viewers: [user(1), department(2)],
    });

    it('チェックを付けているときは、参照者を種類と id の並びで送る', () => {
        expect(rowAccessPayload(draft)).toEqual({
            row_access_restricted: true,
            row_access_viewers: [
                { type: 'user', id: 1 },
                { type: 'department', id: 2 },
            ],
        });
    });

    it('チェックを外すと row_access_restricted だけを送り、欄の値は保つので付け直すと元の参照者を送る', () => {
        const unchecked = { ...draft, restricted: false };

        expect(rowAccessPayload(unchecked)).toEqual({
            row_access_restricted: false,
        });
        expect(rowAccessPayload({ ...unchecked, restricted: true })).toEqual(
            rowAccessPayload(draft),
        );
    });

    it('付けて 0 人なら row_access_viewers は null', () => {
        expect(rowAccessPayload({ restricted: true, viewers: [] })).toEqual({
            row_access_restricted: true,
            row_access_viewers: null,
        });
    });
});

describe('参照者の欄', () => {
    it('選び済みの相手は候補に出ず、同じ id の別の種類の相手は残る', () => {
        const options = [
            { type: 'user' as const, id: 3, label: '利用者3' },
            { type: 'department' as const, id: 3, label: '組織3' },
            { type: 'user' as const, id: 4, label: '利用者4' },
        ];

        expect(
            unpickedOptions(VIEWERS_FIELD, options, [
                { type: 'user', id: 3, label: '利用者3' },
            ]).map((option) => [option.type, option.id]),
        ).toEqual([
            ['department', 3],
            ['user', 4],
        ]);
    });

    it('欄の下の文言は欄のキーか最初の添字の誤りで、添字の誤りはチップの印になる', () => {
        const errors = {
            'row_access_viewers.2': '参照者の値が正しくありません',
            'row_access_viewers.0': '参照者の値が正しくありません',
        };

        expect(fieldErrorOf(errors, VIEWERS_KEY)).toBe(
            '参照者の値が正しくありません',
        );
        expect(
            referenceItemErrors(errors, VIEWERS_KEY, () => {}).invalidIndexes,
        ).toEqual([0, 2]);
    });
});

describe('isRowAccessKey', () => {
    it('チェック・参照者・参照者の添字のキーだけが参照制限の区画の誤り(フォームの上に回さない)', () => {
        expect(
            [
                'row_access_restricted',
                'row_access_viewers',
                'row_access_viewers.0',
                'row_access_viewersx',
                'values.row_access_viewers',
                'record',
            ].map(isRowAccessKey),
        ).toEqual([true, true, true, false, false, false]);
    });
});

describe('restrictedText', () => {
    it('チェックの表示は「制限する」「制限しない」', () => {
        expect([restrictedText(true), restrictedText(false)]).toEqual([
            '制限する',
            '制限しない',
        ]);
    });
});
