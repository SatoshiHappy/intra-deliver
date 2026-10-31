import { describe, expect, it } from 'vite-plus/test';
import type { RecordAdminItem, RecordAdminModule } from '@/types';
import {
    headingGrouping,
    headingOfFields,
    isCountedField,
    itemId,
} from './adminItems';

const items: RecordAdminItem[] = [
    { kind: 'field', code: 'name' },
    { kind: 'heading', id: 7 },
    { kind: 'field', code: 'start' },
    { kind: 'field', code: 'lines' },
    { kind: 'field', code: 'end' },
    { kind: 'heading', id: 9 },
];

describe('itemId', () => {
    it('見出しと項目で重ならない ID', () => {
        expect(itemId({ kind: 'heading', id: 7 })).toBe('heading:7');
        expect(itemId({ kind: 'field', code: 'name' })).toBe('field:name');
    });
});

const notDetails = (code: string) => code !== 'lines';

describe('headingOfFields', () => {
    it('次の見出しの前までの項目をその見出しに入れる(頭の項目は null。明細の項目は入らない)', () => {
        expect([...headingOfFields(items, notDetails)]).toEqual([
            ['name', null],
            ['start', 7],
            ['end', 7],
        ]);
    });

    it('見出しが無ければどの項目も頭のまとまり(null)に入る', () => {
        expect([
            ...headingOfFields([{ kind: 'field', code: 'name' }], () => true),
        ]).toEqual([['name', null]]);
    });
});

describe('headingGrouping', () => {
    it('見出しごとに次の見出しの前までの項目を数える(頭の項目と明細の項目は数えない。項目が 0 の見出しは 0)', () => {
        expect([...headingGrouping(items, notDetails).counts]).toEqual([
            [7, 2],
            [9, 0],
        ]);
    });

    it('頭の数 = 最初の見出しより前の項目の数(明細の項目は数えない)', () => {
        const headCount = (list: RecordAdminItem[], isCounted = notDetails) =>
            headingGrouping(list, isCounted).headCount;

        expect(headCount(items)).toBe(1);
        expect(headCount(items, () => true)).toBe(1);
        expect(
            headCount([
                { kind: 'field', code: 'lines' },
                { kind: 'heading', id: 7 },
                { kind: 'field', code: 'start' },
            ]),
        ).toBe(0);
    });

    it('見出しが無ければ全部の項目が頭のまとまり、項目が無ければ 0', () => {
        const fields: RecordAdminItem[] = [
            { kind: 'field', code: 'a' },
            { kind: 'field', code: 'b' },
        ];

        expect(headingGrouping(fields, () => true).headCount).toBe(2);
        expect(headingGrouping([], () => true).headCount).toBe(0);
    });

    it('字下げする項目 = headingOf に入る項目(明細の項目は入らない)', () => {
        const grouping = headingGrouping(items, notDetails);

        expect(grouping.headingOf.has('name')).toBe(true);
        expect(grouping.headingOf.has('lines')).toBe(false);
    });
});

describe('isCountedField', () => {
    const module = (parent: object | null) =>
        ({
            parent,
            fields: [
                { code: 'name', type: 'text' },
                { code: 'lines', type: 'details' },
            ],
        }) as unknown as RecordAdminModule;

    it('明細の項目は数えない', () => {
        const counted = isCountedField(module(null));

        expect(counted('name')).toBe(true);
        expect(counted('lines')).toBe(false);
    });

    it('明細のモジュール(親を持つ。見出しを持たない)は、どの項目も数えない', () => {
        expect(isCountedField(module({ code: 'p', name: 'P' }))('name')).toBe(
            false,
        );
    });
});
