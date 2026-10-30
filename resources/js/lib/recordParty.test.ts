import { describe, expect, it } from 'vite-plus/test';
import { recordField } from './recordField.testing';
import { partiesOf, partyKindOf } from './recordParty';

const parties = recordField('parties', 'user_or_department', {
    isMultiple: true,
});
const host = recordField('host', 'user_or_department');
const members = recordField('members', 'user', { isMultiple: true });
const owner = recordField('owner', 'user');

/** 参照の値(コード = c<id>) */
function referenceValue(id: number, label: string | null) {
    return { id, code: `c${id}`, label, affiliation: null };
}

/** 型「ユーザーと組織」の相手 */
function party(type: 'user' | 'department', id: number) {
    return { type, ...referenceValue(id, `相手 ${id}`) };
}

describe('partiesOf(種類の印を付ける相手の並び)', () => {
    it('型「ユーザーと組織」の項目は、1 つの値も複数の値も相手の並びを返す', () => {
        const values = [party('user', 3), party('department', 3)];

        expect(partiesOf(parties, values)).toEqual(values);
        expect(partiesOf(host, party('department', 7))).toEqual([
            party('department', 7),
        ]);
    });

    it('未設定と空の並びは null', () => {
        expect(partiesOf(parties, null)).toBeNull();
        expect(partiesOf(parties, [])).toBeNull();
    });

    it('ほかの参照の型の項目は、種類を持つ値でも相手の並びを返さない(印を付けない)', () => {
        expect(partiesOf(members, [party('user', 3)])).toBeNull();
        expect(partiesOf(owner, party('user', 3))).toBeNull();
    });
});

describe('partiesOf(起きない値は投げる)', () => {
    it('型「ユーザーと組織」の項目の値に参照の値でない要素があれば投げる', () => {
        expect(() => partiesOf(host, 'u0003')).toThrow();
        expect(() => partiesOf(host, { code: 'open', name: '受付' })).toThrow();
    });

    it('種類の無い参照の値は partiesOf が通し、相手ごとの種類を取る partyKindOf が投げる', () => {
        const values = partiesOf(parties, [referenceValue(3, '高橋 誠')]) ?? [];

        expect(() =>
            values.map((value) => partyKindOf(parties, value)),
        ).toThrow();
    });
});
