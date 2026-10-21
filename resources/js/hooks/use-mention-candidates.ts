import { useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import type { Dispatch, SetStateAction } from 'react';
import type { RecordMentionCandidate } from '@/types';

/** 最後の入力から要求を送るまでの待ち(参照の候補・共通マスタの検索と同じ) */
const DEBOUNCE_MS = 200;

/** 宛先の候補の状態(形と意味は use-record-candidates と同じ) */
export type MentionCandidatesState = {
    result: RecordMentionCandidate[] | null;
    isWaiting: boolean;
    hasFailed: boolean;
};

const INITIAL_STATE: MentionCandidatesState = {
    result: null,
    isWaiting: false,
    hasFailed: false,
};

/**
 * 要求の結果を状態にする受け手(最新の要求の結果だけを採る。失敗は onError(422)・onHttpException(403・5xx)・onNetworkError から作る)。
 */
function stateHandlers(
    isLatest: () => boolean,
    setState: Dispatch<SetStateAction<MentionCandidatesState>>,
) {
    const fail = () => {
        if (isLatest()) {
            setState((current) => ({
                ...current,
                isWaiting: false,
                hasFailed: true,
            }));
        }
    };

    return {
        onSuccess: (result: RecordMentionCandidate[]) => {
            if (isLatest()) {
                setState({ result, isWaiting: false, hasFailed: false });
            }
        },
        onError: fail,
        onHttpException: fail,
        onNetworkError: fail,
    };
}

/**
 * コメントの宛先の候補(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 2 節)。語(前後の空白を除いて 1 文字以上)を受け、
 * 最後の入力から 200ms 後に候補の口(URL は呼び手が渡す。JSON の配列)へ要求を送る。語が空なら要求を送らず結果を捨てる(口も語が空なら空を返す)。
 * 前の要求は中止せず、要求ごとの連番で最新の要求の結果だけを採る(use-master-search と同じ)。
 */
export function useMentionCandidates(
    candidatesUrl: string,
    query: string,
): MentionCandidatesState {
    const http = useHttp<Record<string, never>, RecordMentionCandidate[]>({});
    const latestRequest = useRef(0);
    const [state, setState] = useState<MentionCandidatesState>(INITIAL_STATE);
    const keyword = query.trim();

    const discard = useEffectEvent(() => {
        latestRequest.current += 1;
        setState(INITIAL_STATE);
    });

    const send = useEffectEvent((request: number) => {
        const handlers = stateHandlers(
            () => request === latestRequest.current,
            setState,
        );

        http.get(
            `${candidatesUrl}?q=${encodeURIComponent(keyword)}`,
            handlers,
        ).catch(() => {
            // 失敗は handlers の onHttpException・onNetworkError で状態にした(get の Promise はそれらの後に reject する)。
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
    }, [keyword, candidatesUrl]);

    return state;
}
