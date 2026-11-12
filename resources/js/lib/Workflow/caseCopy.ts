import { create as applicationCreate } from '@/routes/workflow/applications';

/**
 * 申請の詳細のボタン「複製」の移り先(元の申請のフローの新規の申請の画面にクエリ copy。applicant は付けず、既定の申請者は申請の画面の
 * 規則で決まる = docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の 12.2 節)。
 */
export function copyRouteOf(source: { id: number; flow: { code: string } }) {
    return applicationCreate(
        { flow: source.flow.code },
        { query: { copy: source.id } },
    );
}
