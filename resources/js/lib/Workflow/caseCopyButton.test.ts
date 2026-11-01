import { describe, expect, it } from 'vite-plus/test';
import { copyHrefOf } from './applyRoutes';
import { headerBarOf } from './caseActions';
import type { WorkflowCaseShowProps } from '@/types';

// 申請の詳細の「複製して申請」(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の 12.2 節、
// 検査.md の 14 節の 12.2 のうち画面の部品のテストの層)。

type Can = WorkflowCaseShowProps['can'];

const can: Can = {
    pullBack: false,
    reapply: false,
    withdraw: false,
    confirm: false,
    operate: false,
};

describe('見出しの右のボタンの並びの「複製して申請」', () => {
    it('canCopy が真なら処理のボタンの後ろ(確認の後)に出る', () => {
        const buttons = headerBarOf({
            nodeActions: [],
            can: { ...can, reapply: true, confirm: true },
            canCopy: true,
        });

        expect(buttons.map((button) => button.kind)).toEqual([
            'reapply',
            'confirm',
            'copy',
        ]);
    });

    it('canCopy が偽なら出ない。処理のボタンが無くても canCopy だけで出る', () => {
        expect(headerBarOf({ nodeActions: [], can, canCopy: false })).toEqual(
            [],
        );
        expect(headerBarOf({ nodeActions: [], can, canCopy: true })).toEqual([
            { kind: 'copy' },
        ]);
    });
});

describe('「複製して申請」の移り先', () => {
    it('元の申請のフローの新規の申請の画面に、クエリ copy = 元の申請の id を付ける(applicant は付けない)', () => {
        expect(copyHrefOf({ id: 42, flow: { code: 'project_plan' } })).toBe(
            '/workflow/flows/project_plan/apply?copy=42',
        );
    });
});
