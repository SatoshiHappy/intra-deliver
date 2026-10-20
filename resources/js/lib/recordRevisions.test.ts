import { describe, expect, it } from 'vite-plus/test';
import type { RecordRevisionField } from '@/types';
import { detailRowName, detailRowSummary } from './recordRevisions';

const field = (
    code: string,
    name: string,
    type: RecordRevisionField['type'],
): RecordRevisionField => ({
    code,
    name,
    type,
    isMultiline: false,
    referencedModule: null,
});

const fields = [
    field('item', '品名', 'string'),
    field('qty', '数量', 'integer'),
    field('price', '単価', 'integer'),
];

describe('detailRowName と detailRowSummary', () => {
    it('行の表示は先頭の項目の値、要約は後の項目の「<名前> <値>」', () => {
        const values = { item: '看板の製作', qty: 1, price: 200000 };

        expect(detailRowName(fields, values)).toBe('看板の製作');
        expect(detailRowSummary(fields, values)).toBe('数量 1　単価 200,000');
    });

    it('値の無い項目は「—」、行が無ければ空', () => {
        expect(detailRowSummary(fields, { item: 'A' })).toBe('数量 —　単価 —');
        expect(detailRowName(fields, null)).toBe('');
        expect(detailRowSummary(fields, null)).toBe('');
    });
});
