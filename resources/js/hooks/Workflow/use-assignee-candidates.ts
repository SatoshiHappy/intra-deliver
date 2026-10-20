import { useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { assigneeCandidates } from '@/routes/workflow/cases';
import type { WorkflowAssigneeCandidate } from '@/types';

/** 最後の入力から要求を送るまでの待ち(参照の候補・共通マスタの検索と同じ) */
const DEBOUNCE_MS = 200;

/** 候補の状態(形と意味は use-record-candidates と同じ) */
export type AssigneeCandidatesState = {
    users: WorkflowAssigneeCandidate[] | null;
    isWaiting: boolean;
    hasFailed: boolean;
};

const INITIAL_STATE: AssigneeCandidatesState = {
    users: null,
    isWaiting: false,
    hasFailed: false,
};

/**
 * 処理対象者の候補(docs の 設計/ワークフロー/ルートの深さ/画面.md 1.1・6 節。口 = workflow.cases.assignee-candidates)。案件・指定されるステップ・語を受け、
 * 最後の入力から 200ms 後に useHttp で要求を送る。語が空なら送らない(口も空を返す)。古い応答の捨て方・失敗の扱いは use-record-candidates と同じ規則。
 */
export function useAssigneeCandidates(
    caseId: number,
    nodeId: number,
    query: string,
): AssigneeCandidatesState {
    const http = useHttp<
        Record<string, never>,
        { users: WorkflowAssigneeCandidate[] }
    >({});
    const latestRequest = useRef(0);
    const [state, setState] = useState<AssigneeCandidatesState>(INITIAL_STATE);
    const keyword = query.trim();

    const discard = useEffectEvent(() => {
        latestRequest.current += 1;
        setState(INITIAL_STATE);
    });

    const send = useEffectEvent((request: number) => {
        const isLatest = () => request === latestRequest.current;
        const fail = () => {
            if (isLatest()) {
                setState((current) => ({
                    ...current,
                    isWaiting: false,
                    hasFailed: true,
                }));
            }
        };

        http.get(
            assigneeCandidates.url(caseId, {
                query: { node: nodeId, q: keyword },
            }),
            {
                onSuccess: (result) => {
                    if (isLatest()) {
                        setState({
                            users: result.users,
                            isWaiting: false,
                            hasFailed: false,
                        });
                    }
                },
                onError: fail,
                onHttpException: fail,
                onNetworkError: fail,
            },
        ).catch(() => {
            // 失敗は上の onHttpException・onNetworkError で状態にした(get の Promise はそれらの後に reject する)。
        });
    });

    useEffect(() => {
        if (keyword === '') {
            discard();

            return;
        }

        const request = ++latestRequest.current;
        setState((current) => ({ ...current, isWaiting: true }));
        const timer = window.setTimeout(() => send(request), DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [keyword, caseId, nodeId]);

    return state;
}
