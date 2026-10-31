import { describe, expect, it } from 'vite-plus/test';
import type { RecordAdminItem } from '@/types';
import { headingFieldCounts, headingOfFields, itemId } from './adminItems';

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

describe('headingOfFields', () => {
    it('見出しの後ろの項目を次の見出しの前までその見出しに入れる(頭の項目と明細の項目は入らない)', () => {
        const headingOf = headingOfFields(items, (code) => code !== 'lines');

        expect([...headingOf]).toEqual([
            ['start', 7],
            ['end', 7],
        ]);
    });

    it('見出しが無ければどの項目も入らない', () => {
        expect(
            headingOfFields([{ kind: 'field', code: 'name' }], () => true).size,
        ).toBe(0);
    });
});

describe('headingFieldCounts', () => {
    it('次の見出しの前までの項目を数える(頭の項目と明細の項目は数えない。項目が 0 の見出しは 0)', () => {
        const counts = headingFieldCounts(items, (code) => code !== 'lines');

        expect([...counts]).toEqual([
            [7, 2],
            [9, 0],
        ]);
    });
});
