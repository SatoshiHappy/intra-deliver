import { describe, expect, it } from 'vite-plus/test';
import type {
    RecordFieldDefinition,
    RecordFieldType,
    RecordModuleDefinition,
} from '@/types';
import { recordField } from './recordField.testing';
import { currentDraft, drawnDetailsFields, payloadValue } from './recordForm';

function field(
    code: string,
    type: RecordFieldType,
    childModule: RecordModuleDefinition | null = null,
): RecordFieldDefinition {
    return recordField(code, type, { childModule });
}

function moduleOf(
    code: string,
    fields: RecordFieldDefinition[],
): RecordModuleDefinition {
    return { code, name: code, description: null, fields, rules: [] };
}

const lineModule = moduleOf('line', [
    field('item', 'string'),
    field('amount', 'integer'),
]);

const issueModule = moduleOf('issue', [
    field('title', 'string'),
    field('lines', 'details', lineModule),
    field('orphan', 'details', null),
]);

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

describe('currentDraft', () => {
    it('UTC の日時を Asia/Tokyo の欄の値にする', () => {
        expect(
            currentDraft(field('f', 'datetime'), '2026-10-11T00:30:00Z'),
        ).toBe('2026-10-11T09:30');
    });

    it('複数選べる参照は相手ごとに選んだ状態にする(表示名が無ければコード。所属は今の値の所属)', () => {
        expect(
            currentDraft(recordField('members', 'user', { isMultiple: true }), [
                {
                    id: 9,
                    code: 'u0003',
                    label: '高橋 誠',
                    affiliation: '総務部',
                },
                { id: 14, code: 'u0014', label: null, affiliation: null },
            ]),
        ).toEqual([
            { id: 9, label: '高橋 誠', affiliation: '総務部' },
            { id: 14, label: 'u0014', affiliation: null },
        ]);
    });

    it('欄の値にしてから送る値に戻すと、同じ時刻を表す', () => {
        const datetime = field('f', 'datetime');
        const sent = payloadValue(
            datetime,
            currentDraft(datetime, '2026-10-11T00:30:00Z'),
        );

        expect(Date.parse(String(sent))).toBe(
            Date.parse('2026-10-11T00:30:00Z'),
        );
    });
});

describe('drawnDetailsFields', () => {
    it('子の業務モジュールが無い明細は描かない', () => {
        expect(drawnDetailsFields(issueModule).map((f) => f.code)).toEqual([
            'lines',
        ]);
    });
});
