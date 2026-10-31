import { useCandidateSearch } from '@/hooks/Workflow/use-candidate-search';
import type { CandidateSearchState } from '@/hooks/Workflow/use-candidate-search';
import { subjectCandidates } from '@/routes/workflow/admin';
import type {
    WorkflowSubjectCandidatesResponse,
    WorkflowSubjectType,
} from '@/types';

/**
 * 対象者の候補(参照者とステップの設定の「対象者で指定」。口 = workflow.admin.subject-candidates。docs の
 * 設計/ワークフロー/ルートの深さ/画面/README.md の 1.1)。種類ごとに今日の有効なユーザー・組織・役職・ロールから語で引く。
 * 種類が変わると取り直す。語が空なら送らない(口も空を返す)。
 */
export function useAdminSubjectCandidates(
    type: WorkflowSubjectType,
    query: string,
): CandidateSearchState {
    return useCandidateSearch(
        type,
        (keyword) => subjectCandidates.url({ query: { type, q: keyword } }),
        (result: WorkflowSubjectCandidatesResponse) => result.candidates,
        query,
    );
}
