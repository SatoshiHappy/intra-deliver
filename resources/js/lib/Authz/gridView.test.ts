import { describe, expect, it } from 'vite-plus/test';
import type { AuthzGridJudgement, AuthzGridRow } from '@/types';
import {
    blockedActions,
    decidingNames,
    showsActionRow,
    visibleRows,
} from './gridView';

function row(
    resourceGroupId: number,
    parentId: number | null,
    code: string,
    name: string | null = null,
    judgements: AuthzGridJudgement[] | null = null,
): AuthzGridRow {
    return {
        resourceGroupId,
        parentId,
        depth: 0,
        code,
        name,
        settings: [],
        judgements,
    };
}

// 1 総務 ─ 2 人事 ─ 3 給与、4 営業
const rows = [
    row(1, null, 'general', '総務'),
    row(2, 1, 'hr', '人事'),
    row(3, 2, 'payroll', null),
    row(4, null, 'sales', '営業'),
];

const ids = (shown: AuthzGridRow[]) => shown.map((r) => r.resourceGroupId);

describe('blockedActions', () => {
    it('判定が閉塞のアクションだけを並べる', () => {
        const judged = row(1, null, 'a', null, [
            { action: 'read', result: 'block', decidingSubjectGroupIds: [] },
            { action: 'update', result: 'permit', decidingSubjectGroupIds: [] },
            { action: 'admin', result: 'block', decidingSubjectGroupIds: [] },
        ]);

        expect(blockedActions(judged)).toEqual(['read', 'admin']);
    });

    it('判定しない行は空', () => {
        expect(blockedActions(row(1, null, 'a'))).toEqual([]);
    });
});

describe('visibleRows', () => {
    it('語が無ければ、畳んだ行の配下(孫を含む)を除く', () => {
        expect(ids(visibleRows(rows, '', new Set([1])))).toEqual([1, 4]);
    });

    it('語があれば、表示名(無ければコード)に部分一致する行と祖先を、畳んでいても出す', () => {
        expect(ids(visibleRows(rows, ' PAY ', new Set([1, 2])))).toEqual([
            1, 2, 3,
        ]);
        expect(ids(visibleRows(rows, '人事', new Set()))).toEqual([1, 2]);
    });
});

describe('decidingNames', () => {
    it('対象者の表示名を並べ、列に無い ID は #<id>', () => {
        expect(decidingNames([2, 9], new Map([[2, '営業部']]))).toEqual([
            '営業部',
            '#9',
        ]);
    });
});

describe('showsActionRow', () => {
    it('アクションが 2 つ以上のときだけ出す', () => {
        expect(showsActionRow(['read'])).toBe(false);
        expect(showsActionRow(['read', 'admin'])).toBe(true);
    });
});
