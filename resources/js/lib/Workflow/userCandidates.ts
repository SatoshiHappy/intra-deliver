import { userCandidates } from '@/routes/workflow';

/**
 * 人の候補の口 workflow.user-candidates の URL(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.3 節)。
 * 案件があれば案件の申請基準日、無ければ on(代理の登録の欄が入力中の開始日を送る)、どちらも無ければ口が今日にする。
 */
export function userCandidatesUrl(
    keyword: string,
    caseId: number | null,
    on?: string,
): string {
    return userCandidates.url({
        query: {
            q: keyword,
            ...(caseId === null ? {} : { case: caseId }),
            ...(on === undefined ? {} : { on }),
        },
    });
}
