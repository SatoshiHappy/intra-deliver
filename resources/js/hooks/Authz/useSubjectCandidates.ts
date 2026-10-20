import { useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import type { SubjectCandidatesState } from '@/lib/Authz/subjectCandidates';
import {
    INITIAL_SUBJECT_CANDIDATES,
    waitingFor,
} from '@/lib/Authz/subjectCandidates';
import { subjectCandidates } from '@/routes/authz/editor';
import type { AuthzSubjectCandidates, AuthzSubjectKind } from '@/types';

/** 語の最大の文字数(サーバーの AuthzSubjectCandidatesRequest::MAX_LENGTH と同じ値) */
export const SUBJECT_QUERY_MAX_LENGTH = 100;

/** 最後の入力から要求を送るまでの待ち(共通マスタの検索と同じ) */
const DEBOUNCE_MS = 200;

/**
 * 「列を追加」の候補(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1)。語(前後の空白を除いて 1 文字以上)を受け、200ms 後に
 * 候補の口へ useHttp で要求を送る。語が空なら送らない(口は空の語に空を返す)。古い応答は要求ごとの連番で捨てる(use-master-search と同じ作り)。
 */
export function useSubjectCandidates(
    kind: AuthzSubjectKind,
    query: string,
): SubjectCandidatesState {
    const http = useHttp<Record<string, never>, AuthzSubjectCandidates>({});
    const latestRequest = useRef(0);
    const [state, setState] = useState<SubjectCandidatesState>(
        INITIAL_SUBJECT_CANDIDATES,
    );
    const keyword = query.trim();

    const send = useEffectEvent((request: number) => {
        // 遅れて届いた古い応答は捨てる(最新の要求の結果だけを採る)
        const settle = (next: Partial<SubjectCandidatesState>) => {
            if (request === latestRequest.current) {
                setState((current) => ({
                    ...current,
                    isWaiting: false,
                    ...next,
                }));
            }
        };
        const fail = () => settle({ hasFailed: true });

        http.get(subjectCandidates.url({ query: { kind, q: keyword } }), {
            onSuccess: (result) => settle({ result, hasFailed: false }),
            onError: fail,
            onHttpException: fail,
            onNetworkError: fail,
        }).catch(fail);
    });

    useEffect(() => {
        if (keyword === '') {
            latestRequest.current += 1; // 待っている要求の応答を採らせない
            setState(INITIAL_SUBJECT_CANDIDATES);

            return;
        }

        const request = ++latestRequest.current;
        setState(waitingFor);
        const timer = window.setTimeout(() => send(request), DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [keyword, kind]);

    return state;
}
