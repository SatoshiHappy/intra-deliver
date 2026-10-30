import { useCandidateSearch } from '@/hooks/Workflow/use-candidate-search';
import type { CandidateSearchState } from '@/hooks/Workflow/use-candidate-search';
import { userCandidatesUrl } from '@/lib/Workflow/userCandidates';
import { assigneeCandidates } from '@/routes/workflow/cases';
import type { WorkflowAssigneeCandidate, WorkflowPerson } from '@/types';

/** 人の候補の口の応答の 1 人(department = 主所属の名前) */
type UserCandidate = WorkflowPerson & { department: string | null };

/** 候補の要求の URL(指定されるステップがあれば処理対象者の候補の口、無ければ人の候補の口) */
function candidatesUrl(
    keyword: string,
    caseId: number | null,
    on: string | undefined,
    nodeId: number | undefined,
): string {
    return caseId !== null && nodeId !== undefined
        ? assigneeCandidates.url(caseId, {
              query: { node: nodeId, q: keyword },
          })
        : userCandidatesUrl(keyword, caseId, on);
}

/**
 * 人の候補(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.3 節。口 = workflow.user-candidates)。caseId があれば案件の申請基準日の
 * 有効なユーザー、無ければ on(代理の登録の欄の開始日)の、それも無ければ今日の有効なユーザー。連絡する人・担当変更先・代理先と代理元・
 * 申請操作の欄が使う。nodeId(指定されるステップ。承認のダイアログの指定の欄)があれば、口は workflow.cases.assignee-candidates(設計/ワークフロー/ルートの深さ/画面.md 6 節)。
 * 基準日・ステップが変わると取り直す(scope に含める)。
 */
export function useUserCandidates(
    caseId: number | null,
    query: string,
    on?: string,
    nodeId?: number,
): CandidateSearchState {
    return useCandidateSearch(
        `${caseId}|${on ?? ''}|${nodeId ?? ''}`,
        (keyword) => candidatesUrl(keyword, caseId, on, nodeId),
        (result: { users: (UserCandidate | WorkflowAssigneeCandidate)[] }) =>
            result.users.map((user) => ({
                id: user.id,
                code: user.code,
                name: user.name,
                mainDepartmentName:
                    'mainDepartmentName' in user
                        ? user.mainDepartmentName
                        : user.department,
            })),
        query,
    );
}
