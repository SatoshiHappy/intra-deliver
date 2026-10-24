import { useCandidateSearch } from '@/hooks/Workflow/use-candidate-search';
import type { CandidateSearchState } from '@/hooks/Workflow/use-candidate-search';
import { assigneeCandidates } from '@/routes/workflow/cases';
import type { WorkflowAssigneeCandidate } from '@/types';

/**
 * 処理対象者の候補(docs の 設計/ワークフロー/ルートの深さ/画面.md 1.1・6 節。口 = workflow.cases.assignee-candidates)。案件・指定されるステップ・語を受ける。
 */
export function useAssigneeCandidates(
    caseId: number,
    nodeId: number,
    query: string,
): CandidateSearchState {
    return useCandidateSearch(
        `${caseId}:${nodeId}`,
        (keyword) =>
            assigneeCandidates.url(caseId, {
                query: { node: nodeId, q: keyword },
            }),
        (result: { users: WorkflowAssigneeCandidate[] }) => result.users,
        query,
    );
}
