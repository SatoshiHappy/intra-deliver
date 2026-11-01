import { describe, expect, it } from 'vite-plus/test';
import type { RecordRevisionField, RecordRevisionView } from '@/types';
import {
    revisionDetailField,
    revisionDetailRows,
    revisionGroupFields,
    revisionHeadingGroups,
} from './recordRevisionView';

const field = (
    code: string,
    name: string,
    type: RecordRevisionField['type'] = 'string',
): RecordRevisionField => ({
    code,
    name,
    type,
    isMultiline: false,
    referencedModule: null,
});

const view: RecordRevisionView = {
    groups: [
        { id: null, name: null, fields: [field('name', '案件名')] },
        {
            id: 7,
            name: '契約',
            fields: [
                field('amount', '契約金額', 'integer'),
                field('on', '契約日', 'date'),
            ],
        },
    ],
    values: { name: '新店舗', amount: 3000000, on: '2026-09-30' },
    details: [
        {
            code: 'items',
            name: '見積項目',
            fields: [field('item', '品名'), field('qty', '数量', 'integer')],
            rows: [{ id: 11, values: { item: '看板', qty: 2 } }],
        },
    ],
    rowAccess: null,
};

describe('revisionHeadingGroups', () => {
    it('見出しのまとまりの id・名前と、まとまりの中の項目のコード(頭のまとまりは id・名前とも null)', () => {
        expect(revisionHeadingGroups(view)).toEqual([
            { id: null, name: null, fieldCodes: ['name'] },
            { id: 7, name: '契約', fieldCodes: ['amount', 'on'] },
        ]);
    });
});

describe('revisionGroupFields', () => {
    it('まとまりの項目を版の写しの並びのまま、値の部品に渡す定義にする', () => {
        expect(
            revisionGroupFields(view, 7).map((item) => [
                item.code,
                item.name,
                item.type,
            ]),
        ).toEqual([
            ['amount', '契約金額', 'integer'],
            ['on', '契約日', 'date'],
        ]);
    });

    it('写しに無いまとまりは空', () => {
        expect(revisionGroupFields(view, 99)).toEqual([]);
    });
});

describe('revisionDetailField と revisionDetailRows', () => {
    it('明細の項目を、列 = 版の写しの明細の項目の定義にする', () => {
        const detail = revisionDetailField(view.details[0]);

        expect(detail).toMatchObject({
            code: 'items',
            name: '見積項目',
            type: 'details',
        });
        expect(detail.childModule?.fields.map((item) => item.name)).toEqual([
            '品名',
            '数量',
        ]);
    });

    it('明細の行は明細のコードごと', () => {
        expect(revisionDetailRows(view)).toEqual({
            items: [{ id: 11, values: { item: '看板', qty: 2 } }],
        });
    });
});
