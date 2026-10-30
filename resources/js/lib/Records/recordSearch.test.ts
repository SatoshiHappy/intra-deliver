import { describe, expect, it } from 'vite-plus/test';
import type { RecordFieldDefinition, RecordFieldType } from '@/types';
import { recordField } from '@/lib/recordField.testing';
import type { SearchDraft } from './recordSearch';
import { draftsOf, filterValue, initialDraft, pickedOf } from './recordSearch';

function field(type: RecordFieldType): RecordFieldDefinition {
    return recordField('f', type, { name: '項目' });
}

function multiple(type: RecordFieldType): RecordFieldDefinition {
    return recordField('f', type, { name: '項目', isMultiple: true });
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

describe('filterValue(型「ユーザーと組織」と複数選べる参照)', () => {
    it('型「ユーザーと組織」の相手は <種類>:<id> で送る', () => {
        const picked = { id: 3, label: '開発部', type: 'department' as const };

        expect(filterValue(field('user_or_department'), picked)).toBe(
            'department:3',
        );
    });

    it('複数選べる参照は相手の並びで送り、空の並びは条件にしない', () => {
        const parties = multiple('user_or_department');

        expect(
            filterValue(parties, [
                { id: 3, label: '高橋', type: 'user' },
                { id: 3, label: '開発部', type: 'department' },
            ]),
        ).toEqual(['user:3', 'department:3']);
        expect(
            filterValue(multiple('user'), [
                { id: 5, label: '高橋' },
                { id: 6, label: '中村' },
            ]),
        ).toEqual(['5', '6']);
        expect(filterValue(parties, [])).toBeUndefined();
    });
});

describe('pickedOf', () => {
    it('読み取りが返した条件の値を、表示名 #<id> の相手にする', () => {
        expect(pickedOf(field('user'), '12')).toEqual({ id: 12, label: '#12' });
        expect(pickedOf(field('user_or_department'), 'department:3')).toEqual({
            id: 3,
            label: '#3',
            type: 'department',
        });
        expect(pickedOf(field('user_or_department'), 'user:3')).toEqual({
            id: 3,
            label: '#3',
            type: 'user',
        });
    });

    it('型に合わない形は読まない', () => {
        expect(pickedOf(field('user'), 'user:3')).toBeNull();
        expect(pickedOf(field('user_or_department'), '3')).toBeNull();
        expect(pickedOf(field('user_or_department'), 'role:3')).toBeNull();
    });
});

describe('initialDraft(検索条件から欄の初期値)', () => {
    it('複数選べる参照は読めた相手の並び、条件が無ければ空の並び', () => {
        const parties = multiple('user_or_department');

        expect(initialDraft(parties, ['user:3', 'x', 'department:5'])).toEqual([
            { id: 3, label: '#3', type: 'user' },
            { id: 5, label: '#5', type: 'department' },
        ]);
        expect(initialDraft(parties, undefined)).toEqual([]);
        expect(initialDraft(field('user_or_department'), undefined)).toBeNull();
    });
});

describe('draftsOf(訪問の後に欄を作り直す)', () => {
    const host = recordField('host', 'user_or_department', { name: '主催' });
    const parties = recordField('parties', 'user_or_department', {
        name: '関係者',
        isMultiple: true,
    });
    const user3 = { id: 3, label: '高橋 誠', type: 'user' as const };
    const department5 = { id: 5, label: '開発部', type: 'department' as const };
    const rebuilt = (
        filters: Record<string, string | string[]>,
        current: Record<string, SearchDraft>,
    ) => draftsOf([host, parties], { filters, sort: '' }, current);

    it('同じ相手(種類と id)なら今の欄の表示名を残す(複数は条件の並びの順)', () => {
        const drafts = rebuilt(
            { host: 'user:3', parties: ['department:5', 'user:3'] },
            { host: user3, parties: [user3, department5] },
        );

        expect(drafts).toEqual({
            host: user3,
            parties: [department5, user3],
        });
    });

    it('ユーザー 3 を選んだ欄に組織 3 が返ったら、表示名を残さない', () => {
        const drafts = rebuilt(
            { host: 'department:3', parties: ['department:3'] },
            { host: user3, parties: [user3] },
        );
        const department3 = { id: 3, label: '#3', type: 'department' };

        expect(drafts).toEqual({ host: department3, parties: [department3] });
    });

    it('条件が無ければ、1 つの欄は null、複数の欄は空の並び', () => {
        expect(rebuilt({}, { host: user3, parties: [user3] })).toEqual({
            host: null,
            parties: [],
        });
    });
});
