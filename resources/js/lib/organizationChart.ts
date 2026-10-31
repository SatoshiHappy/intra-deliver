import { organizationChart } from '@/routes/master';

/**
 * 組織図の行き先(docs の 設計/共通マスタ/検索と組織図.md 3.2 の「選んだとき」)。検索の結果とワークフローの名前の札が同じ URL を作るための 1 か所
 * (設計/ワークフロー/申請の周辺/メニューと導線.md 2.1)。<M>/ は別の <M>/ に依存できないので、共通の直下に置く。
 */

/**
 * ユーザーの行き先: 主所属があればその組織を選んでその人を強調、無ければ強調だけ(頂点が選ばれる)。
 */
export function userChartUrl(
    userId: number,
    mainDepartmentId: number | null,
): string {
    return organizationChart.url({
        query:
            mainDepartmentId === null
                ? { user: userId }
                : { department: mainDepartmentId, user: userId },
    });
}

/**
 * 組織の行き先(その組織を選ぶ)。
 */
export function departmentChartUrl(departmentId: number): string {
    return organizationChart.url({ query: { department: departmentId } });
}
