import { useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { search } from '@/routes/master';
import type { MasterSearchResult } from '@/types';

/** 最後の入力から要求を送るまでの待ち(docs の 設計/共通マスタ/検索と組織図.md 3.2) */
const DEBOUNCE_MS = 200;

/**
 * 検索の状態。result = 最後に採った結果(まだ無ければ null)、isWaiting = 要求を待っている(前の結果は出したまま)、
 * hasFailed = 最後の要求が失敗した(403・422・5xx・通信の失敗)。
 */
export type MasterSearchState = {
    result: MasterSearchResult | null;
    isWaiting: boolean;
    hasFailed: boolean;
};

const INITIAL_STATE: MasterSearchState = {
    result: null,
    isWaiting: false,
    hasFailed: false,
};

/**
 * 共通マスタの検索(docs の 設計/共通マスタ/検索と組織図.md 3.2・3.4)。語(前後の空白を除いて 1 文字以上)を受け、最後の入力から 200ms 後に
 * 検索の口へ useHttp で要求を送る。容器(Popover・Dialog)に依らない。
 * 語が空のときは、既定では要求を送らない(ヘッダーの検索欄)。searchesEmpty が真なら、語が空のままでも口を呼ぶ(q を付けない = 先頭から上限まで。
 * 参照の選択欄が開いた間だけ真にする = 設計/業務データの入れ物/画面/README.md 4 節)。空の語は待たずに送り、打ち始めた後の語は 200ms 待つ。
 *
 * 前の要求は中止しない(useHttp は要求ごとの中止を持たない。1 つの中止の口を要求の間で共有するので、中止は最初の 1 回しか効かない)。
 * 要求ごとの連番で最新の要求の結果だけを採り、遅れて届いた古い応答は捨てる。フックの response・processing は、後から終わった要求が書き換えるので使わない。
 * 失敗は onError(422)・onHttpException(403・5xx)・onNetworkError から作る。語が空になったら連番を進めて結果を捨てる
 * (ページ遷移では容器が語を空にする)。
 */
export function useMasterSearch(
    query: string,
    searchesEmpty = false,
): MasterSearchState {
    const http = useHttp<Record<string, never>, MasterSearchResult>({});
    const latestRequest = useRef(0);
    const [state, setState] = useState<MasterSearchState>(INITIAL_STATE);
    const keyword = query.trim();

    /** 結果を捨てる(連番を進めると、待っている要求の応答は採られない) */
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

        http.get(search.url({ query: keyword === '' ? {} : { q: keyword } }), {
            onSuccess: (result) => {
                if (isLatest()) {
                    setState({ result, isWaiting: false, hasFailed: false });
                }
            },
            onError: fail,
            onHttpException: fail,
            onNetworkError: fail,
        }).catch(() => {
            // 失敗は上の onHttpException・onNetworkError で状態にした(get の Promise はそれらの後に reject する)。
        });
    });

    useEffect(() => {
        if (keyword === '' && !searchesEmpty) {
            discard();

            return;
        }

        const request = ++latestRequest.current;
        setState((current) => ({ ...current, isWaiting: true }));

        if (keyword === '') {
            send(request);

            return;
        }

        const timer = window.setTimeout(() => send(request), DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [keyword, searchesEmpty]);

    return state;
}
