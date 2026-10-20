import type { AuthzSubjectCandidates } from '@/types';

/**
 * 候補の状態。result = 最後に採った結果(まだ無ければ null)、isWaiting = 要求を待っている、hasFailed = 最後の要求が失敗した。
 */
export type SubjectCandidatesState = {
    result: AuthzSubjectCandidates | null;
    isWaiting: boolean;
    hasFailed: boolean;
};

export const INITIAL_SUBJECT_CANDIDATES: SubjectCandidatesState = {
    result: null,
    isWaiting: false,
    hasFailed: false,
};

/**
 * 新しい要求を待つ状態(前の要求の失敗は、再試行の間は表示に残さない。結果は応答が届くまで残す)。
 */
export function waitingFor(
    current: SubjectCandidatesState,
): SubjectCandidatesState {
    return { ...current, isWaiting: true, hasFailed: false };
}
