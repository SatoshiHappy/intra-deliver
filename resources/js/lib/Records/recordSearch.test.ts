import { describe, expect, it } from 'vite-plus/test';
import type { RecordFieldDefinition, RecordFieldType } from '@/types';
import { recordField } from '@/lib/recordField.testing';
import { filterValue } from './recordSearch';

function field(type: RecordFieldType): RecordFieldDefinition {
    return recordField('f', type, { name: '項目' });
}

describe('filterValue', () => {
    it('空の欄は条件にしない', () => {
        expect(filterValue(field('string'), '')).toBeUndefined();
        expect(filterValue(field('user'), null)).toBeUndefined();
        expect(
            filterValue(field('integer'), { from: '', to: '' }),
        ).toBeUndefined();
    });

    it('文字列の欄はそのまま送る', () => {
        expect(filterValue(field('string'), '障害')).toBe('障害');
    });

    it('参照は選んだ相手の id を文字列で送る', () => {
        expect(filterValue(field('user'), { id: 12, label: '山田' })).toBe(
            '12',
        );
    });

    it('日時の範囲は下限に :00、上限に :59 と Asia/Tokyo の時差を付ける', () => {
        expect(
            filterValue(field('datetime'), {
                from: '2026-10-11T09:30',
                to: '2026-10-11T18:00',
            }),
        ).toEqual({
            from: '2026-10-11T09:30:00+09:00',
            to: '2026-10-11T18:00:59+09:00',
        });
    });

    it('日時でない範囲は値をそのまま送る', () => {
        expect(
            filterValue(field('date'), {
                from: '2026-10-01',
                to: '2026-10-31',
            }),
        ).toEqual({ from: '2026-10-01', to: '2026-10-31' });
    });

    it('片側だけの範囲は、空の側を送らない', () => {
        expect(
            filterValue(field('datetime'), {
                from: '',
                to: '2026-10-11T09:30',
            }),
        ).toEqual({ from: undefined, to: '2026-10-11T09:30:59+09:00' });
        expect(filterValue(field('integer'), { from: '10', to: '' })).toEqual({
            from: '10',
            to: undefined,
        });
    });
});
