import { describe, expect, it } from 'vite-plus/test';
import type { WorkflowCurrentNode } from '@/types';
import { caseRow } from './caseRow.testing';
import {
    columnsOf,
    countOf,
    currentNodeGroups,
    dueOf,
    elapsedDaysOf,
    emptyTextOf,
    namesText,
    sublineNumberOf,
} from './caseList';

describe('columnsOf(選択の欄を持つタブ)', () => {
    it('未処理は選択・申請名・申請者・現在のステップ・代理元・経過日数・期限で、操作の列を持たない', () => {
        expect(columnsOf('pending')).toEqual([
            'select',
            'name',
            'applicant',
            'currentStep',
            'principals',
            'elapsed',
            'due',
        ]);
    });

    it('未確認は選択・申請名・申請者・現在のステップ・確認するステップ・確認が開いた日時', () => {
        expect(columnsOf('unconfirmed')).toEqual([
            'select',
            'name',
            'applicant',
            'currentStep',
            'confirmSteps',
            'confirmOpenedAt',
        ]);
    });
});

describe('columnsOf(選択の欄を持たないタブ)', () => {
    it('確認済は選択の欄を持たず、確認したステップ・確認日時', () => {
        expect(columnsOf('confirmed')).toEqual([
            'name',
            'applicant',
            'currentStep',
            'confirmedSteps',
            'confirmedAt',
        ]);
    });

    it('処理済・参照は申請番号とフローの列を持たず(申請名の 2 行目)、申請日・状態・操作を持つ', () => {
        const expected = [
            'name',
            'applicant',
            'appliedAt',
            'currentStep',
            'state',
            'actions',
        ];

        expect(columnsOf('processed')).toEqual(expected);
        expect(columnsOf('referenced')).toEqual(expected);
    });

    it('一時保存は申請名・申請者・状態・操作', () => {
        expect(columnsOf('draft')).toEqual([
            'name',
            'applicant',
            'state',
            'actions',
        ]);
    });
});

describe('countOf', () => {
    const counts = { draft: 1, pending: 5, unconfirmed: 2 };

    it('一時保存・未処理・未確認だけが件数を持つ', () => {
        expect([
            countOf('draft', counts),
            countOf('pending', counts),
            countOf('unconfirmed', counts),
        ]).toEqual([1, 5, 2]);
        expect(countOf('processed', counts)).toBeNull();
        expect(countOf('confirmed', counts)).toBeNull();
        expect(countOf('referenced', counts)).toBeNull();
    });
});

describe('emptyTextOf', () => {
    it('検索の語が無ければタブの文', () => {
        expect(emptyTextOf('unconfirmed', '')).toBe(
            '確認を待っている申請はありません',
        );
        expect(emptyTextOf('confirmed', '')).toBe('確認した申請はありません');
        expect(emptyTextOf('pending', '')).toBe(
            '処理を待っている申請はありません',
        );
    });

    it('検索の語があればタブによらず「該当する申請はありません」', () => {
        expect(emptyTextOf('draft', 'WF')).toBe('該当する申請はありません');
        expect(emptyTextOf('confirmed', '計画')).toBe(
            '該当する申請はありません',
        );
    });
});

describe('dueOf と elapsedDaysOf', () => {
    it('期限超過は期限日(YYYY/MM/DD)と超過の印', () => {
        const deadline = {
            reachedOn: '2026-10-06',
            elapsedDays: 9,
            dueOn: '2026-10-11',
            overdue: true,
        };

        expect(dueOf(deadline)).toEqual({ date: '2026/10/11', overdue: true });
        expect(elapsedDaysOf(deadline)).toBe(9);
    });

    it('期限の当日は超過でない', () => {
        expect(
            dueOf({
                reachedOn: '2026-10-12',
                elapsedDays: 3,
                dueOn: '2026-10-15',
                overdue: false,
            }),
        ).toEqual({ date: '2026/10/15', overdue: false });
    });

    it('期限が無い・項目が無い行は「—」(date = null)、経過日数も無ければ null', () => {
        const noDue = {
            reachedOn: '2026-10-09',
            elapsedDays: 6,
            dueOn: null,
            overdue: false,
        };

        expect(dueOf(noDue)).toEqual({ date: null, overdue: false });
        expect(dueOf(null)).toEqual({ date: null, overdue: false });
        expect(elapsedDaysOf(null)).toBeNull();
        expect(
            elapsedDaysOf({ ...noDue, reachedOn: null, elapsedDays: null }),
        ).toBeNull();
    });
});

describe('namesText', () => {
    it('「、」で並べ、無ければ「—」', () => {
        expect(namesText(['課長', '部長'])).toBe('課長、部長');
        expect(namesText([])).toBe('—');
    });
});

describe('sublineNumberOf', () => {
    it('申請名の 2 行目の番号(一時保存は番号を出さずフロー名だけ)', () => {
        expect(sublineNumberOf(caseRow(3))).toBe('WF-000003');
        expect(
            sublineNumberOf(caseRow(4, { state: 'draft', number: null })),
        ).toBeNull();
    });
});

const node = (
    id: number,
    parentId: number | null,
    parentName: string | null,
    own: string,
): WorkflowCurrentNode => ({
    id,
    name: parentName === null ? own : `${parentName} ${own}`,
    parentId,
    parentName,
    childName: parentName === null ? null : own,
    state: 'waiting',
});

/** まとまりごとの [親の名前, ノードの id の並び] */
const groupShape = (nodes: WorkflowCurrentNode[]) =>
    currentNodeGroups(nodes).map((group) => [
        group.parentName,
        group.nodes.map((member) => member.id),
    ]);

describe('currentNodeGroups(まとめる所と切る所)', () => {
    it('展開の行でなければ 1 つずつ、同じ親の子が続く所は 1 つにまとめ、次の別のノードで切る', () => {
        expect(
            groupShape([
                node(1, null, null, '課長'),
                node(2, 10, '関係部署の合議', '承認 1'),
                node(3, 10, '関係部署の合議', '承認 3'),
                node(4, null, null, '責任者'),
            ]),
        ).toEqual([
            [null, [1]],
            ['関係部署の合議', [2, 3]],
            [null, [4]],
        ]);
    });

    it('現在のノードが無ければ空', () => {
        expect(currentNodeGroups([])).toEqual([]);
    });
});

describe('currentNodeGroups(違う親)', () => {
    it('子が 1 つでもまとまりになり、違う親の子が続けば分ける', () => {
        expect(
            groupShape([
                node(1, 10, '関係部署の合議', '承認 1'),
                node(2, 20, '事業部の配置', '承認 2'),
            ]),
        ).toEqual([
            ['関係部署の合議', [1]],
            ['事業部の配置', [2]],
        ]);
    });

    it('同じ名前で別の親の子が続いても、親の id が違えば分ける', () => {
        expect(
            groupShape([
                node(1, 10, '合議', '承認 1'),
                node(2, 20, '合議', '承認 1'),
            ]),
        ).toEqual([
            ['合議', [1]],
            ['合議', [2]],
        ]);
    });
});
