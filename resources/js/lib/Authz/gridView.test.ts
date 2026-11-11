import { describe, expect, it } from 'vite-plus/test';
import type { AuthzGridJudgement, AuthzGridRow } from '@/types';
import {
    blockedActions,
    decidingNames,
    featureOf,
    featureOptions,
    showsActionRow,
    visibleRows,
} from './gridView';

function row(
    resourceGroupId: number,
    parentId: number | null,
    code: string,
    name: string | null = null,
    judgements: AuthzGridJudgement[] | null = null,
    depth = 0,
): AuthzGridRow {
    return {
        resourceGroupId,
        parentId,
        depth,
        code,
        name,
        description: null,
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
        const withJudgements = row(1, null, 'a', null, [
            { action: 'read', result: 'block', decidingSubjectGroupIds: [] },
            { action: 'update', result: 'permit', decidingSubjectGroupIds: [] },
            { action: 'admin', result: 'block', decidingSubjectGroupIds: [] },
        ]);

        expect(blockedActions(withJudgements)).toEqual(['read', 'admin']);
    });

    it('判定しない行は空', () => {
        expect(blockedActions(row(1, null, 'a'))).toEqual([]);
    });
});

// 木(深さ優先): 1 総務 ─ 2 人事 ─ 3 給与(表示名なし)─ 5 賞与、1 総務 ─ 6 経理 ─ 7 予算、4 営業 ─ 8 東京
const tree = [
    row(1, null, 'general', '総務', null, 0),
    row(2, 1, 'hr', '人事', null, 1),
    row(3, 2, 'payroll', null, null, 2),
    row(5, 3, 'bonus', '賞与', null, 3),
    row(6, 1, 'accounting', '経理', null, 1),
    row(7, 6, 'budget', '予算', null, 2),
    row(4, null, 'sales', '営業', null, 0),
    row(8, 4, 'tokyo', '東京', null, 1),
];

/** 表示の範囲で選ぶ行(人事)。範囲 = 2・3・5、祖先 = 1、範囲の外で祖先でない行 = 6・7・4・8 */
const HR = 2;

const view = (query: string, collapsed: number[], featureId: number | null) => {
    const visible = visibleRows(tree, query, new Set(collapsed), featureId);

    return { ids: ids(visible.rows), toggleable: [...visible.toggleable] };
};

// docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.3「縦軸の絞り込み」の表の 12 行(行を出すかと開閉の記号)
describe('visibleRows(縦軸の絞り込みの表の 1〜4 = 表示の範囲がすべて)', () => {
    it('1: すべて・語が空・祖先に畳んだ行が無い行は出し、子のある行に記号を出す', () => {
        expect(view('', [], null)).toEqual({
            ids: [1, 2, 3, 5, 6, 7, 4, 8],
            toggleable: [1, 2, 3, 6, 4],
        });
    });

    it('2: すべて・語が空・祖先に畳んだ行がある行は出さない(孫を含む)', () => {
        expect(view('', [1], null)).toEqual({
            ids: [1, 4, 8],
            toggleable: [1, 4],
        });
    });

    it('3: すべて・語あり・行が一致するか配下に一致する行がある行は出し、記号を出さない(畳んでいても)', () => {
        expect(view(' PAY ', [1, 2], null)).toEqual({
            ids: [1, 2, 3],
            toggleable: [],
        });
        expect(view('人事', [], null).ids).toEqual([1, 2]);
    });

    it('4: すべて・語あり・行も配下も一致しない行は出さない', () => {
        expect(view('東京', [], null).ids).toEqual([4, 8]);
    });

    it('検索は名前(無ければコード)だけを照合し、説明にだけある語では行を残さない', () => {
        const described = tree.map((r) =>
            r.resourceGroupId === 2
                ? { ...r, description: '給与の明細を表示できます' }
                : r,
        );

        expect(
            ids(visibleRows(described, '明細', new Set(), null).rows),
        ).toEqual([]);
        expect(
            ids(visibleRows(described, '人事', new Set(), null).rows),
        ).toEqual([1, 2]);
    });
});

describe('visibleRows(縦軸の絞り込みの表の 5〜8 = 範囲の外の行)', () => {
    it('5: 行を選ぶ・範囲の外で選んだ行の祖先でない行は、語や畳みによらず出さない', () => {
        expect(view('', [], HR).ids).not.toContain(6);
        expect(view('', [4], HR).ids).not.toContain(4);
        expect(view('経理', [], HR).ids).toEqual([]);
        expect(view('東京', [], HR).ids).toEqual([]);
    });

    it('6: 行を選ぶ・選んだ行の祖先・語が空は、畳んでいても出し、記号を出さない', () => {
        expect(view('', [1], HR)).toEqual({
            ids: [1, 2, 3, 5],
            toggleable: [2, 3],
        });
    });

    it('7: 行を選ぶ・選んだ行の祖先・語あり・範囲の中に一致する行があれば出し、記号を出さない', () => {
        expect(view('賞与', [1], HR)).toEqual({
            ids: [1, 2, 3, 5],
            toggleable: [],
        });
    });

    it('8: 行を選ぶ・選んだ行の祖先・語あり・範囲の中に一致する行が無ければ出さない(範囲の外の一致は数えない)', () => {
        expect(view('総務', [], HR).ids).toEqual([]);
        expect(view('予算', [], HR).ids).toEqual([]);
    });
});

describe('visibleRows(縦軸の絞り込みの表の 9〜12 = 範囲の中の行)', () => {
    it('9: 行を選ぶ・範囲の中・語が空・範囲の中の祖先に畳んだ行が無ければ出し、子のある行に記号を出す', () => {
        expect(view('', [], HR)).toEqual({
            ids: [1, 2, 3, 5],
            toggleable: [2, 3],
        });
    });

    it('10: 行を選ぶ・範囲の中・語が空・範囲の中の祖先(選んだ行を含む)に畳んだ行があれば出さない。選んだ行はいつも出す', () => {
        expect(view('', [HR], HR)).toEqual({
            ids: [1, 2],
            toggleable: [2],
        });
        expect(view('', [3], HR)).toEqual({
            ids: [1, 2, 3],
            toggleable: [2, 3],
        });
    });

    it('11: 行を選ぶ・範囲の中・語あり・行が一致するか範囲の中の配下に一致する行があれば出し、記号を出さない', () => {
        expect(view('PAYROLL', [HR], HR)).toEqual({
            ids: [1, 2, 3],
            toggleable: [],
        });
    });

    it('12: 行を選ぶ・範囲の中・語あり・行も範囲の中の配下も一致しない行は出さない', () => {
        expect(view('人事', [], HR).ids).toEqual([1, 2]);
    });
});

describe('visibleRows(開閉の状態)', () => {
    it('絞り込みは畳んだ行を変えない(表示の範囲で絞って畳んだ行は、すべてに戻しても畳んだまま)', () => {
        const collapsed = new Set([3]);

        expect(ids(visibleRows(tree, '', collapsed, HR).rows)).toEqual([
            1, 2, 3,
        ]);
        expect(ids(visibleRows(tree, '', collapsed, null).rows)).toEqual([
            1, 2, 3, 6, 7, 4, 8,
        ]);
        expect([...collapsed]).toEqual([3]);
    });

    it('表示の範囲を省くと、すべての行が範囲', () => {
        expect(ids(visibleRows(rows, '', new Set([1])).rows)).toEqual([1, 4]);
    });
});

describe('featureOptions・featureOf', () => {
    it('選択肢は深さ 1 の行を並びのまま', () => {
        expect(ids(featureOptions(tree))).toEqual([2, 6, 8]);
    });

    it('深さ 1 の行のコードならその行、無い値・深さ 1 に無い値・null は null(すべて)', () => {
        expect(featureOf(tree, 'accounting')?.resourceGroupId).toBe(6);
        expect(featureOf(tree, 'abc')).toBeNull();
        expect(featureOf(tree, 'general')).toBeNull();
        expect(featureOf(tree, 'payroll')).toBeNull();
        expect(featureOf(tree, null)).toBeNull();
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
