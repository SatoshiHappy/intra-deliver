import { useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import type { WorkflowAssigneeCandidate } from '@/types';

/** 最後の入力から要求を送るまでの待ち(参照の候補・共通マスタの検索と同じ) */
const DEBOUNCE_MS = 200;

/** 候補の状態(形と意味は use-record-candidates と同じ。users = 候補の並び。人の候補が既定で、定義の管理の対象者の候補は種類つきの候補) */
export type CandidateSearchState<Item = WorkflowAssigneeCandidate> = {
    users: Item[] | null;
    isWaiting: boolean;
    hasFailed: boolean;
};

const INITIAL_STATE = {
    users: null,
    isWaiting: false,
    hasFailed: false,
};

type Http<Result> = ReturnType<typeof useHttp<Record<string, never>, Result>>;

/**
 * 候補を取る要求を送り、最新の要求の応答だけを状態にする(失敗は状態にする)。
 */
function requestCandidates<Result, Item>(
    http: Http<Result>,
    url: string,
    usersOf: (result: Result) => Item[],
    isLatest: () => boolean,
    setState: (
        update: (
            current: CandidateSearchState<Item>,
        ) => CandidateSearchState<Item>,
    ) => void,
): void {
    const fail = () => {
        if (isLatest()) {
            setState((current) => ({
                ...current,
                isWaiting: false,
                hasFailed: true,
            }));
        }
    };

    http.get(url, {
        onSuccess: (result) => {
            if (isLatest()) {
                setState(() => ({
                    users: usersOf(result),
                    isWaiting: false,
                    hasFailed: false,
                }));
            }
        },
        onError: fail,
        onHttpException: fail,
        onNetworkError: fail,
    }).catch(() => {
        // 失敗は上の onHttpException・onNetworkError で状態にした(get の Promise はそれらの後に reject する)。
    });
}

/**
 * 人の候補の検索(処理対象者の候補の口と人の候補の口が共通に使う)。scope = 候補の対象(案件・ステップ。変わると取り直す)、
 * urlOf = 語から要求の URL、usersOf = 応答から候補を取り出す。最後の入力から 200ms 後に useHttp で送り、語が空なら送らない(口も空を返す)。
 * 古い応答の捨て方・失敗の扱いは use-record-candidates と同じ規則。
 */
export function useCandidateSearch<Result, Item = WorkflowAssigneeCandidate>(
    scope: string,
    urlOf: (keyword: string) => string,
    usersOf: (result: Result) => Item[],
    query: string,
): CandidateSearchState<Item> {
    const http = useHttp<Record<string, never>, Result>({});
    const latestRequest = useRef(0);
    const [state, setState] =
        useState<CandidateSearchState<Item>>(INITIAL_STATE);
    const keyword = query.trim();

    const discard = useEffectEvent(() => {
        latestRequest.current += 1;
        setState(INITIAL_STATE);
    });

    const send = useEffectEvent((request: number) =>
        requestCandidates(
            http,
            urlOf(keyword),
            usersOf,
            () => request === latestRequest.current,
            setState,
        ),
    );

    useEffect(() => {
        if (keyword === '') {
            discard();

            return;
        }

        const request = ++latestRequest.current;
        setState((current) => ({ ...current, isWaiting: true }));
        const timer = window.setTimeout(() => send(request), DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [keyword, scope]);

    return state;
}
