import { describe, expect, it } from 'vite-plus/test';
import {
    SUBJECT_TYPES,
    draftsOf,
    rowsPayloadOf,
    rowsSignature,
    withAddedRow,
    withRowPicked,
    withRowType,
    withoutRow,
} from './subjectRows';

const ROWS = [
    { type: 'department', id: 5, name: '経理部' },
    { type: 'role', id: 2, name: 'ワークフロー管理者' },
] as const;

describe('SUBJECT_TYPES', () => {
    it('ユーザー・組織・役職・ロールの順', () => {
        expect(SUBJECT_TYPES.map((item) => item.value)).toEqual([
            'user',
            'department',
            'company_post',
            'role',
        ]);
    });
});

describe('draftsOf', () => {
    it('行に並びの番号の key を付ける', () => {
        expect(draftsOf([...ROWS])).toEqual([
            { key: 0, type: 'department', id: 5, name: '経理部' },
            { key: 1, type: 'role', id: 2, name: 'ワークフロー管理者' },
        ]);
    });
});

describe('withAddedRow', () => {
    it('未選択のユーザーの行を末尾に足し、key は既存の最大 + 1(0 行なら 0)', () => {
        expect(withAddedRow([])).toEqual([
            { key: 0, type: 'user', id: null, name: null },
        ]);
        const added = withAddedRow(withoutRow(draftsOf([...ROWS]), 0));

        expect(added.map((row) => row.key)).toEqual([1, 2]);
        expect(added[1]).toEqual({
            key: 2,
            type: 'user',
            id: null,
            name: null,
        });
    });
});

describe('withRowType', () => {
    it('種類を替えた行は相手を選び直しにし、ほかの行はそのまま', () => {
        const next = withRowType(draftsOf([...ROWS]), 0, 'user');

        expect(next[0]).toEqual({ key: 0, type: 'user', id: null, name: null });
        expect(next[1]).toEqual(draftsOf([...ROWS])[1]);
    });
});

describe('withRowPicked', () => {
    it('選んだ相手の id と名前を入れる', () => {
        const next = withRowPicked(withAddedRow([]), 0, {
            id: 9,
            name: '高橋 誠',
        });

        expect(next).toEqual([
            { key: 0, type: 'user', id: 9, name: '高橋 誠' },
        ]);
    });
});

describe('rowsPayloadOf', () => {
    it('種類と id だけを並びのまま送り、未選択は id を null で送る', () => {
        expect(rowsPayloadOf(withAddedRow(draftsOf([...ROWS])))).toEqual([
            { type: 'department', id: 5 },
            { type: 'role', id: 2 },
            { type: 'user', id: null },
        ]);
        expect(rowsPayloadOf([])).toEqual([]);
    });
});

describe('rowsSignature', () => {
    it('種類と id の並びで、名前には依らない', () => {
        expect(rowsSignature([...ROWS])).toBe('department:5,role:2');
        expect(
            rowsSignature([{ type: 'department', id: 5, name: '改名後' }]),
        ).toBe('department:5');
        expect(rowsSignature([])).toBe('');
    });
});
