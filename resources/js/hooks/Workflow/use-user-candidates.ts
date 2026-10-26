import { useCandidateSearch } from '@/hooks/Workflow/use-candidate-search';
import type { CandidateSearchState } from '@/hooks/Workflow/use-candidate-search';
import { userCandidatesUrl } from '@/lib/Workflow/userCandidates';
import type { WorkflowPerson } from '@/types';

/** 人の候補の口の応答の 1 人(department = 主所属の名前) */
type UserCandidate = WorkflowPerson & { department: string | null };

/**
 * 人の候補(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.3 節。口 = workflow.user-candidates)。caseId があれば案件の申請基準日の
 * 有効なユーザー、無ければ on(代理の登録の欄の開始日)の、それも無ければ今日の有効なユーザー。連絡する人・担当変更先・代理先と代理元の欄が使う。
 * 基準日が変わると取り直す(scope に含める)。
 */
export function useUserCandidates(
    caseId: number | null,
    query: string,
    on?: string,
): CandidateSearchState {
    return useCandidateSearch(
        `${caseId}|${on ?? ''}`,
        (keyword) => userCandidatesUrl(keyword, caseId, on),
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
