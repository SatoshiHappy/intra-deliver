import { describe, expect, it } from 'vite-plus/test';
import { copyRouteOf } from './caseCopy';

// 申請の詳細のボタン「複製」の移り先(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の 12.2 節)。

describe('「複製」の移り先', () => {
    it('元の申請のフローの新規の申請の画面に、クエリ copy = 元の申請の id を付ける(applicant は付けない)', () => {
        const route = copyRouteOf({ id: 42, flow: { code: 'project_plan' } });

        expect([route.method, route.url]).toEqual([
            'get',
            '/workflow/flows/project_plan/apply?copy=42',
        ]);
    });
});
