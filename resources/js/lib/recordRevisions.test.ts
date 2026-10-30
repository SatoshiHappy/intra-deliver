import { describe, expect, it } from 'vite-plus/test';
import type { RecordRevisionField } from '@/types';
import { detailRowCells } from './recordRevisions';

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

describe('detailRowCells', () => {
    it('項目の並びに、名前と値の部品に渡す定義と値(値の無い項目は null)', () => {
        const cells = detailRowCells(fields, { item: '看板の製作', qty: 1 });

        expect(
            cells.map((cell) => [cell.name, cell.definition.type, cell.value]),
        ).toEqual([
            ['品名', 'string', '看板の製作'],
            ['数量', 'integer', 1],
            ['単価', 'integer', null],
        ]);
    });

    it('種類を持つ値はそのまま渡す(値の部品が種類の印を付ける)', () => {
        const party = {
            type: 'department' as const,
            id: 3,
            code: 'development',
            label: '開発部',
            affiliation: null,
        };
        const cells = detailRowCells(
            [
                field('item', '品名', 'string'),
                field('who', '担当', 'user_or_department'),
            ],
            { item: 'A', who: party },
        );

        expect(cells[1]?.value).toEqual(party);
    });

    it('行が無ければ空', () => {
        expect(detailRowCells(fields, null)).toEqual([]);
    });
});
