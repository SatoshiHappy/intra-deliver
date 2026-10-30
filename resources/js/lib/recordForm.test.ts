import { describe, expect, it } from 'vite-plus/test';
import type {
    RecordFieldDefinition,
    RecordFieldType,
    RecordModuleDefinition,
} from '@/types';
import { recordField } from './recordField.testing';
import {
    currentDraft,
    drawnDetailsFields,
    isReferenceField,
} from './recordForm';
import { payloadValue } from './recordPayload';

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

        expect(typeof sent).toBe('string');
        expect(Date.parse(typeof sent === 'string' ? sent : '')).toBe(
            Date.parse('2026-10-11T00:30:00Z'),
        );
    });
});

describe('型「ユーザーと組織」の欄', () => {
    const parties = recordField('parties', 'user_or_department', {
        isMultiple: true,
    });
    const host = recordField('host', 'user_or_department');

    it('参照の項目として扱う(参照の選択欄と複数の参照の欄)', () => {
        expect(isReferenceField(parties)).toBe(true);
        expect(isReferenceField(host)).toBe(true);
    });

    it('今の値の相手は種類を持った選んだ状態になる(名前の無い相手はコードに種類を添える)', () => {
        expect(
            currentDraft(parties, [
                {
                    type: 'user',
                    id: 3,
                    code: 'u0003',
                    label: '高橋 誠',
                    affiliation: '総務部',
                },
                {
                    type: 'department',
                    id: 3,
                    code: 'development',
                    label: null,
                    affiliation: null,
                },
            ]),
        ).toEqual([
            { id: 3, label: '高橋 誠', affiliation: '総務部', type: 'user' },
            {
                id: 3,
                label: 'development(組織)',
                affiliation: null,
                type: 'department',
            },
        ]);
    });
});

describe('型「ユーザーと組織」の欄(読み取りの形に合わない今の値)', () => {
    const host = recordField('host', 'user_or_department');

    it('今の値に種類が無ければ投げる(読み取りの形に合わない)', () => {
        expect(() =>
            currentDraft(host, {
                id: 3,
                code: 'u0003',
                label: '高橋 誠',
                affiliation: null,
            }),
        ).toThrow();
    });
});

describe('drawnDetailsFields', () => {
    it('子の業務モジュールが無い明細は描かない', () => {
        expect(drawnDetailsFields(issueModule).map((f) => f.code)).toEqual([
            'lines',
        ]);
    });
});
