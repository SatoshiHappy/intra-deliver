import { useCandidateSearch } from '@/hooks/Workflow/use-candidate-search';
import type { CandidateSearchState } from '@/hooks/Workflow/use-candidate-search';
import { subjectCandidates } from '@/routes/workflow/admin';
import type { WorkflowSubjectCandidatesResponse } from '@/types';

/** 候補の群 1 つ(種類と、その種類の候補) */
export type SubjectCandidateResponseGroup =
    WorkflowSubjectCandidatesResponse['groups'][number];

/**
 * 対象者の候補(参照者とステップの設定の「対象者で指定」。口 = workflow.admin.subject-candidates。docs の
 * 設計/ワークフロー/ルートの深さ/画面/README.md の 1.1)。1 つの語で今日の有効なユーザー・組織・役職・ロールをまとめて引き、
 * 種類ごとの群(users = 群の並び)で返す。語が空なら送らない(口も空の群を返す)。
 */
export function useAdminSubjectCandidates(
    query: string,
): CandidateSearchState<SubjectCandidateResponseGroup> {
    return useCandidateSearch(
        'subjects',
        (keyword) => subjectCandidates.url({ query: { q: keyword } }),
        (result: WorkflowSubjectCandidatesResponse) => result.groups,
        query,
    );
}
