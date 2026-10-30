import { describe, expect, it } from 'vite-plus/test';
import type { RecordFieldDefinition, RecordFieldType } from '@/types';
import { recordField } from './recordField.testing';
import { payloadValue } from './recordPayload';

function field(code: string, type: RecordFieldType): RecordFieldDefinition {
    return recordField(code, type);
}

describe('payloadValue', () => {
    it('日時の欄の値に :00 と Asia/Tokyo の時差を付ける', () => {
        expect(payloadValue(field('f', 'datetime'), '2026-10-11T09:30')).toBe(
            '2026-10-11T09:30:00+09:00',
        );
    });

    it('整数・小数の欄は文字列のまま送る', () => {
        expect(payloadValue(field('f', 'integer'), '10')).toBe('10');
        expect(payloadValue(field('f', 'decimal'), '1.5')).toBe('1.5');
    });

    it('空の欄は null', () => {
        expect(payloadValue(field('f', 'string'), '')).toBeNull();
        expect(payloadValue(field('f', 'datetime'), '')).toBeNull();
        expect(payloadValue(field('f', 'user'), null)).toBeNull();
    });

    it('真偽は真偽、参照は id で送る', () => {
        expect(payloadValue(field('f', 'boolean'), true)).toBe(true);
        expect(payloadValue(field('f', 'boolean'), '')).toBe(false);
        expect(payloadValue(field('f', 'user'), { id: 7, label: '山田' })).toBe(
            7,
        );
    });
});

describe('payloadValue(複数選べる参照)', () => {
    const members = recordField('members', 'user', { isMultiple: true });

    it('選んだ相手の id を並びの順の配列で送る', () => {
        expect(
            payloadValue(members, [
                { id: 9, label: '高橋 誠' },
                { id: 2, label: '中村 美咲' },
            ]),
        ).toEqual([9, 2]);
    });

    it('0 個と未選択は null', () => {
        expect(payloadValue(members, [])).toBeNull();
        expect(payloadValue(members, null)).toBeNull();
    });
});

describe('payloadValue(型「ユーザーと組織」)', () => {
    const parties = recordField('parties', 'user_or_department', {
        isMultiple: true,
    });
    const host = recordField('host', 'user_or_department');

    it('送る値は種類と id(複数は並びの順の配列。0 個は null)', () => {
        expect(
            payloadValue(parties, [
                { id: 3, label: '高橋 誠', type: 'user' },
                { id: 3, label: '開発部', type: 'department' },
            ]),
        ).toEqual([
            { type: 'user', id: 3 },
            { type: 'department', id: 3 },
        ]);
        expect(
            payloadValue(host, { id: 7, label: '開発部', type: 'department' }),
        ).toEqual({ type: 'department', id: 7 });
        expect(payloadValue(parties, [])).toBeNull();
        expect(payloadValue(host, null)).toBeNull();
    });

    it('送る形は項目の型で決める(ほかの参照の型は相手が種類を持っても id)', () => {
        const members = recordField('members', 'user', { isMultiple: true });
        const owner = recordField('owner', 'user');
        const user = { id: 3, label: '高橋 誠', type: 'user' as const };

        expect(payloadValue(members, [user])).toEqual([3]);
        expect(payloadValue(owner, user)).toBe(3);
    });

    it('種類の無い相手は起きないので、黙って id にせず投げる(1 つと複数)', () => {
        const untyped = { id: 3, label: '高橋 誠' };

        expect(() => payloadValue(parties, [untyped])).toThrow();
        expect(() => payloadValue(host, untyped)).toThrow();
    });
});
