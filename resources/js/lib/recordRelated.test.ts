import { describe, expect, it } from 'vite-plus/test';
import { recordField } from './recordField.testing';
import {
    addedMessage,
    hasLinkValue,
    movedColumns,
    relatedDialogStatus,
    relatedFormData,
    relatedTotal,
    selectedRelatedKey,
    toggledColumns,
    updatedDate,
} from './recordRelated';

const entry = (key: string, count: number) => ({
    key,
    name: key,
    count,
    canCreate: true,
});

describe('selectedRelatedKey', () => {
    const keys = ['estimates.customer', 'issues.project'];

    it('クエリ related が入口のキーのときだけそれを選び、ほかは先頭の入口', () => {
        expect(
            selectedRelatedKey('/r/1?tab=related&related=issues.project', keys),
        ).toBe('issues.project');
        expect(selectedRelatedKey('/r/1?related=nothing', keys)).toBe(
            'estimates.customer',
        );
        expect(selectedRelatedKey('/r/1', keys)).toBe('estimates.customer');
        expect(selectedRelatedKey('/r/1?related=issues.project', [])).toBe(
            null,
        );
    });
});

describe('relatedTotal', () => {
    it('入口の件数の和(0 の入口も含む)', () => {
        expect(
            relatedTotal([entry('a', 3), entry('b', 0), entry('c', 2)]),
        ).toBe(5);
        expect(relatedTotal([])).toBe(0);
    });
});

describe('hasLinkValue', () => {
    it('空の値(null・空文字・空の配列)は #<id> を出す側', () => {
        expect(hasLinkValue('Q-0012')).toBe(true);
        expect(hasLinkValue(0)).toBe(true);
        expect(hasLinkValue(false)).toBe(true);
        expect(hasLinkValue(null)).toBe(false);
        expect(hasLinkValue('')).toBe(false);
        expect(hasLinkValue([])).toBe(false);
    });
});

describe('relatedFormData', () => {
    it('既定値に、入口の項目だけこのレコードを選んだ値を入れる', () => {
        const project = {
            id: 7,
            code: null,
            label: '案件 A',
            affiliation: null,
        };
        const data = relatedFormData({
            entry: 'estimates.customer',
            field: 'customer',
            value: project,
            module: {
                code: 'estimates',
                name: '見積',
                description: null,
                fields: [
                    recordField('title', 'string', { defaultValue: '見積' }),
                    recordField('customer', 'record', {
                        referencedModule: { code: 'projects', name: '案件' },
                    }),
                ],
                rules: [],
            },
            headings: [],
            rowAccess: { restricted: false, viewers: [] },
        });

        expect(data.values).toEqual({
            title: '見積',
            customer: { id: 7, label: '案件 A', affiliation: null },
        });
    });
});

describe('addedMessage', () => {
    it('登録した数の 1 行', () => {
        expect(addedMessage(2)).toBe('2 件登録しました。');
    });
});

describe('toggledColumns', () => {
    it('外すと並びから除き、付けると末尾に足し、5 つを超えては足さない', () => {
        expect(toggledColumns(['a', 'b'], 'a')).toEqual(['b']);
        expect(toggledColumns(['a'], 'b')).toEqual(['a', 'b']);
        expect(toggledColumns(['a', 'b', 'c', 'd', 'e'], 'f')).toEqual([
            'a',
            'b',
            'c',
            'd',
            'e',
        ]);
    });
});

describe('movedColumns', () => {
    it('上へ・下へ動かし、端を越えては動かさない', () => {
        expect(movedColumns(['a', 'b', 'c'], 1, -1)).toEqual(['b', 'a', 'c']);
        expect(movedColumns(['a', 'b', 'c'], 1, 1)).toEqual(['a', 'c', 'b']);
        expect(movedColumns(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
        expect(movedColumns(['a', 'b'], 1, 1)).toEqual(['a', 'b']);
    });
});

describe('updatedDate', () => {
    it('更新日時を Asia/Tokyo の日付にする', () => {
        expect(updatedDate('2026-10-15T16:00:00+00:00')).toBe('2026/10/16');
        expect(updatedDate('x')).toBe('x');
    });
});

describe('relatedDialogStatus', () => {
    const form = {
        entry: 'e.f',
        field: 'f',
        value: { id: 1, code: null, label: null, affiliation: null },
        module: {
            code: 'e',
            name: 'e',
            description: null,
            fields: [],
            rules: [],
        },
        headings: [],
        rowAccess: { restricted: false, viewers: [] },
    };

    it('フォームが届けばフォーム、届かずに要求が終われば読み込めなかった、終わる前は読み込み中', () => {
        expect(relatedDialogStatus(form, true)).toBe('ready');
        expect(relatedDialogStatus(null, true)).toBe('failed');
        expect(relatedDialogStatus(null, false)).toBe('loading');
    });

    it('閉じた後(フォームを残し、要求は終わったまま)は読み込めなかったに見えない', () => {
        expect(relatedDialogStatus(form, true)).not.toBe('failed');
    });
});
