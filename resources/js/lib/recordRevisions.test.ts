import { describe, expect, it } from 'vite-plus/test';
import type { RecordRevisionChanges, RecordRevisionField } from '@/types';
import {
    detailRowCells,
    hasRevisionLines,
    rowAccessLines,
} from './recordRevisions';

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

describe('rowAccessLines', () => {
    const viewer = (type: 'user' | 'department', id: number) => ({
        type,
        id,
        code: null,
        label: `相手${id}`,
        affiliation: null,
    });

    it('変化が無ければ行も無い', () => {
        expect(rowAccessLines(null)).toEqual([]);
        expect(rowAccessLines({ restricted: null, viewers: null })).toEqual([]);
    });

    it('チェックの変化は「参照制限」の行で、値は「制限しない」「制限する」', () => {
        const [line] = rowAccessLines({
            restricted: { before: false, after: true },
            viewers: null,
        });

        expect([line?.field.name, line?.before, line?.after]).toEqual([
            '参照制限',
            '制限しない',
            '制限する',
        ]);
    });

    it('外して参照者が消えた保存は「参照制限」→「参照者」の順の 2 行で、参照者は「ユーザーと組織」の値として描く', () => {
        const lines = rowAccessLines({
            restricted: { before: true, after: false },
            viewers: { before: [viewer('department', 2)], after: [] },
        });

        expect(
            lines.map((line) => [line.field.name, line.field.type, line.after]),
        ).toEqual([
            ['参照制限', 'string', '制限しない'],
            ['参照者', 'user_or_department', []],
        ]);
        expect(lines[1]?.before).toEqual([viewer('department', 2)]);
    });
});

const none: RecordRevisionChanges = {
    fields: [],
    details: [],
    attachments: [],
    rowAccess: null,
};

const detailWith = (
    rows: RecordRevisionChanges['details'][number]['rows'],
): RecordRevisionChanges => ({
    ...none,
    details: [{ code: 'lines', name: '明細', fields, rows }],
});

const row = (
    change: 'added' | 'removed' | 'changed',
    changedCodes: string[],
) => ({ change, rowId: 1, before: null, after: null, changedCodes });

describe('hasRevisionLines の項目・添付・参照制限', () => {
    const attachment = {
        change: 'added' as const,
        id: 1,
        name: 'a.pdf',
        uploadId: 1,
        mime: 'application/pdf',
        previewable: false,
        purgeDueOn: null,
        downloadUrl: null,
        previewUrl: null,
    };
    const title = {
        code: 'title',
        name: '題',
        field: field('title', '題', 'string'),
        before: 'a',
        after: 'b',
    };

    it('何も無ければ描く行は無い(箱を出さない)', () => {
        expect(hasRevisionLines(none)).toBe(false);
        expect(
            hasRevisionLines({
                ...none,
                rowAccess: { restricted: null, viewers: null },
            }),
        ).toBe(false);
    });

    it('項目・添付・参照制限のどれか 1 つでも描く行がある', () => {
        expect(hasRevisionLines({ ...none, fields: [title] })).toBe(true);
        expect(hasRevisionLines({ ...none, attachments: [attachment] })).toBe(
            true,
        );
        expect(
            hasRevisionLines({
                ...none,
                rowAccess: {
                    restricted: { before: false, after: true },
                    viewers: null,
                },
            }),
        ).toBe(true);
    });
});

describe('hasRevisionLines の明細', () => {
    it('行が無ければ描く行は無い', () => {
        expect(hasRevisionLines(detailWith([]))).toBe(false);
    });

    it('追加・削除の行はそのまま 1 行', () => {
        expect(hasRevisionLines(detailWith([row('added', [])]))).toBe(true);
        expect(hasRevisionLines(detailWith([row('removed', [])]))).toBe(true);
    });

    it('更新の行は、変えた項目が明細の項目に当たるときだけ描く', () => {
        expect(hasRevisionLines(detailWith([row('changed', ['qty'])]))).toBe(
            true,
        );
        expect(hasRevisionLines(detailWith([row('changed', ['other'])]))).toBe(
            false,
        );
        expect(hasRevisionLines(detailWith([row('changed', [])]))).toBe(false);
    });
});
