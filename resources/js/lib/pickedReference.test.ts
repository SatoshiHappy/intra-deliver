import { describe, expect, it } from 'vite-plus/test';
import { recordField } from '@/lib/recordField.testing';
import {
    pickedGroups,
    pickedKey,
    pickedMarkOf,
    unpickedOptions,
} from './pickedReference';

const parties = recordField('parties', 'user_or_department', {
    isMultiple: true,
});
const host = recordField('host', 'user_or_department');
const owner = recordField('owner', 'user');
const user = { id: 3, label: '高橋', type: 'user' as const };
const department = { id: 3, label: '開発部', type: 'department' as const };

describe('pickedKey', () => {
    it('型「ユーザーと組織」の項目は <種類>:<id>、ほかの参照の型は相手が種類を持っても id', () => {
        expect(pickedKey(parties, user)).toBe('user:3');
        expect(pickedKey(parties, department)).toBe('department:3');
        expect(pickedKey(owner, { id: 3, label: '高橋' })).toBe('3');
        expect(pickedKey(owner, user)).toBe('3');
    });

    it('型「ユーザーと組織」の項目の種類の無い相手は起きないので投げる', () => {
        expect(() => pickedKey(parties, { id: 3, label: '高橋' })).toThrow();
    });
});

describe('unpickedOptions', () => {
    it('型「ユーザーと組織」の項目はユーザー 3 を選んでも組織 3 を残し、同じ種類と id の相手は除く', () => {
        expect(unpickedOptions(parties, [user, department], [user])).toEqual([
            department,
        ]);
    });

    it('ほかの参照の型は id で除く', () => {
        const picked = { id: 3, label: '高橋' };
        const other = { id: 4, label: '佐藤' };

        expect(unpickedOptions(owner, [picked, other], [picked])).toEqual([
            other,
        ]);
    });
});

describe('pickedGroups', () => {
    it('型「ユーザーと組織」の項目は群「ユーザー」「組織」の見出しつきで、ユーザーの群が先', () => {
        expect(pickedGroups(parties, [department, user])).toEqual([
            { heading: 'ユーザー', options: [user] },
            { heading: '組織', options: [department] },
        ]);
    });

    it('片方の群だけでも見出しを出し、0 件の群は出さない', () => {
        expect(pickedGroups(parties, [department])).toEqual([
            { heading: '組織', options: [department] },
        ]);
        expect(pickedGroups(parties, [])).toEqual([]);
    });

    it('ほかの参照の型は見出しの無い 1 つの群(0 件なら群なし)', () => {
        const plain = { id: 5, label: '総務部' };
        const sections = recordField('sections', 'department');

        expect(pickedGroups(sections, [plain])).toEqual([
            { heading: null, options: [plain] },
        ]);
        expect(pickedGroups(sections, [])).toEqual([]);
    });
});

describe('pickedMarkOf(参照の選択欄のボタンの種類の印)', () => {
    it('型「ユーザーと組織」の項目の相手は種類の印を出す', () => {
        expect(pickedMarkOf(host, user)).toBe('user');
        expect(pickedMarkOf(host, department)).toBe('department');
    });

    it('未選択と、ほかの参照の型の相手は印を出さない', () => {
        expect(pickedMarkOf(host, null)).toBeNull();
        expect(pickedMarkOf(owner, { id: 3, label: '高橋' })).toBeNull();
        expect(pickedMarkOf(owner, user)).toBeNull();
    });
});
