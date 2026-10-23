import { describe, expect, it } from 'vite-plus/test';
import { canOf, caseRow } from './caseRow.testing';
import {
    BULK_OUTCOMES,
    BULK_TEXTS,
    allCheckedState,
    bulkActionOf,
    bulkApprovePayload,
    bulkConfirmPayload,
    guidanceText,
    resultSummaryOf,
    selectableIds,
    selectedRowsOf,
    toggledAll,
    toggledOne,
} from './bulkActions';

const principal = { id: 3, code: 'u0003', name: '高橋 誠' };

describe('bulkActionOf', () => {
    it('未処理は一括承認、未確認は一括確認、ほかのタブは選択の欄を持たない', () => {
        expect(bulkActionOf('pending')).toBe('approve');
        expect(bulkActionOf('unconfirmed')).toBe('confirm');
        for (const tab of [
            'draft',
            'processed',
            'confirmed',
            'referenced',
        ] as const) {
            expect(bulkActionOf(tab)).toBeNull();
        }
    });
});

describe('選択', () => {
    const rows = [
        caseRow(1, { can: canOf({ bulkApprove: true }) }),
        caseRow(2, { can: canOf({ confirm: true }) }),
        caseRow(3, { can: canOf({ bulkApprove: true, confirm: true }) }),
    ];

    it('選べる行は、未処理 = can.bulkApprove、未確認 = can.confirm', () => {
        expect(selectableIds(rows, 'approve')).toEqual([1, 3]);
        expect(selectableIds(rows, 'confirm')).toEqual([2, 3]);
    });

    it('「すべて選択」はそのページの選べる行だけを選び、全部が選ばれていれば外す', () => {
        expect(toggledAll([], [1, 3])).toEqual([1, 3]);
        expect(toggledAll([1], [1, 3])).toEqual([1, 3]);
        expect(toggledAll([3, 1], [1, 3])).toEqual([]);
        expect(toggledAll([], [])).toEqual([]);
    });

    it('行の欄で選び・外す', () => {
        expect(toggledOne([1], 3, true)).toEqual([1, 3]);
        expect(toggledOne([1, 3], 1, false)).toEqual([3]);
        expect(toggledOne([1], 1, true)).toEqual([1]);
    });

    it('「すべて選択」の欄の状態は全部・一部・無し', () => {
        expect(allCheckedState([1, 3], [1, 3])).toBe(true);
        expect(allCheckedState([3], [1, 3])).toBe('indeterminate');
        expect(allCheckedState([], [1, 3])).toBe(false);
        expect(allCheckedState([], [])).toBe(false);
    });

    it('選んだ行は一覧の並び(選んだ順でない)', () => {
        expect(selectedRowsOf(rows, [3, 1]).map((row) => row.id)).toEqual([
            1, 3,
        ]);
    });
});

describe('選べない行の読み上げ', () => {
    it('未処理は一括承認、未確認は一括確認の文(同じ規則を両方のタブに当てる)', () => {
        expect(BULK_TEXTS.approve.notSelectable).toBe(
            'この申請は一括承認できません',
        );
        expect(BULK_TEXTS.confirm.notSelectable).toBe(
            'この申請は一括確認できません',
        );
    });
});

describe('送る形', () => {
    it('一括承認は行の id・改訂番号と、行が示した組(ノード・代理元。自分なら null)を写す', () => {
        const rows = [
            caseRow(14, {
                revision: 5,
                bulkApproval: { nodeId: 74, nodeName: '部長', principal },
            }),
            caseRow(16, {
                revision: 2,
                bulkApproval: {
                    nodeId: 81,
                    nodeName: '課長の承認',
                    principal: null,
                },
            }),
        ];

        expect(bulkApprovePayload(rows, 'まとめて承認します。')).toEqual({
            cases: [
                { id: 14, revision: 5, node_id: 74, principal_user_id: 3 },
                { id: 16, revision: 2, node_id: 81, principal_user_id: null },
            ],
            comment: 'まとめて承認します。',
        });
    });

    it('一括確認は案件の id の並び', () => {
        expect(bulkConfirmPayload([caseRow(13), caseRow(10)])).toEqual({
            case_ids: [13, 10],
        });
    });
});

describe('resultSummaryOf', () => {
    it('一括承認の結果の上の文', () => {
        expect(
            resultSummaryOf({
                action: 'approve',
                total: 3,
                succeeded: 1,
                rows: [],
            }),
        ).toBe(
            '3 件のうち 1 件を承認しました。残りの 2 件は、下の表の「対応」を確認してください。',
        );
    });

    it('一括確認は「確認しました」', () => {
        expect(
            resultSummaryOf({
                action: 'confirm',
                total: 2,
                succeeded: 0,
                rows: [],
            }),
        ).toBe(
            '2 件のうち 0 件を確認しました。残りの 2 件は、下の表の「対応」を確認してください。',
        );
    });
});

describe('結果の表', () => {
    it('結果の札: 承認済み・確認済みは承認待ちの色と印、処理済みは処理の結果の色、未処理は差戻しの色', () => {
        expect(BULK_OUTCOMES).toEqual({
            approved: { label: '承認済み', tone: 'pending', mark: '✓' },
            confirmed: { label: '確認済み', tone: 'pending', mark: '✓' },
            processed: { label: '処理済み', tone: 'settled', mark: null },
            pending: { label: '未処理', tone: 'returned', mark: null },
        });
    });

    it('対応の文はサーバーの文のまま、無ければ「—」', () => {
        expect(guidanceText('もう一度承認してください。')).toBe(
            'もう一度承認してください。',
        );
        expect(guidanceText(null)).toBe('—');
    });
});
