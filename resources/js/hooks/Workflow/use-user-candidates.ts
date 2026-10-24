import { useCandidateSearch } from '@/hooks/Workflow/use-candidate-search';
import type { CandidateSearchState } from '@/hooks/Workflow/use-candidate-search';
import { userCandidates } from '@/routes/workflow';
import type { WorkflowPerson } from '@/types';

/** 人の候補の口の応答の 1 人(department = 主所属の名前) */
type UserCandidate = WorkflowPerson & { department: string | null };

/**
 * 人の候補(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.3 節。口 = workflow.user-candidates)。caseId があれば案件の申請基準日の
 * 有効なユーザー、無ければ今日の有効なユーザー。連絡する人・担当変更先の欄が使う。
 */
export function useUserCandidates(
    caseId: number | null,
    query: string,
): CandidateSearchState {
    return useCandidateSearch(
        String(caseId),
        (keyword) =>
            userCandidates.url({
                query: {
                    q: keyword,
                    ...(caseId === null ? {} : { case: caseId }),
                },
            }),
        (result: { users: UserCandidate[] }) =>
            result.users.map((user) => ({
                id: user.id,
                code: user.code,
                name: user.name,
                mainDepartmentName: user.department,
            })),
        query,
    );
}
