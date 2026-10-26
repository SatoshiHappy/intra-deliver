import { useHttp } from '@inertiajs/react';
import {
    createContext,
    useContext,
    useEffect,
    useEffectEvent,
    useRef,
    useState,
} from 'react';
import { candidates } from '@/routes/records/data';
import type { RecordCandidates } from '@/types';

/**
 * 候補の語の最大の文字数(RecordCandidatesRequest::MAX_LENGTH・Master\SearchRequest::MAX_LENGTH と同じ値)。保存しない語で、超えると 422 で
 * 行き止まるので入力で止める(設計/業務データの入れ物/画面/README.md 4 節)。
 */
export const CANDIDATE_QUERY_MAX_LENGTH = 100;

/** 最後の入力から要求を送るまでの待ち(共通マスタの検索と同じ = docs の 設計/業務データの入れ物/画面/README.md 4 節) */
const DEBOUNCE_MS = 200;

/** 候補の口に送る語と、除くレコードの id(どちらも無ければ付けない) */
type RecordCandidateQuery = { q?: string; exclude?: number };

/** 候補の引き先(口の URL を作る。業務データの入れ物の画面は records.data.candidates、ワークフローの申請の画面は workflow.record-candidates) */
export type RecordCandidateSource = (
    moduleCode: string,
    query: RecordCandidateQuery,
) => string;

/** 既定の引き先 records.data.candidates(業務データの入れ物の画面。docs の 設計/業務データの入れ物/画面/README.md 4 節) */
export const RECORDS_DATA_SOURCE: RecordCandidateSource = (moduleCode, query) =>
    candidates.url({ module: moduleCode }, { query });

/** 引き先の差し替え口。Provider が無ければ既定(参照の選択欄はどの画面でもこの口から引き先を読む) */
export const RecordCandidateSourceContext =
    createContext<RecordCandidateSource>(RECORDS_DATA_SOURCE);

/** 候補の状態(形と意味は use-master-search の MasterSearchState と同じ) */
export type RecordCandidatesState = {
    result: RecordCandidates | null;
    isWaiting: boolean;
    hasFailed: boolean;
};

const INITIAL_STATE: RecordCandidatesState = {
    result: null,
    isWaiting: false,
    hasFailed: false,
};

/**
 * 他モジュール参照の項目の候補(docs の 設計/業務データの入れ物/画面/README.md 4 節)。古い応答の捨て方と失敗の扱いは use-master-search と同じ。
 * searchesEmpty が真なら語が空でも先頭の候補を取る(参照の選択欄が開いている間だけ真にする)。
 */
export function useRecordCandidates(
    moduleCode: string,
    query: string,
    /** 候補から除くレコードの id(書くレコード。null = 除かない) */
    excludedId: number | null,
    searchesEmpty = false,
): RecordCandidatesState {
    const source = useContext(RecordCandidateSourceContext);
    const http = useHttp<Record<string, never>, RecordCandidates>({});
    const latestRequest = useRef(0);
    const [state, setState] = useState<RecordCandidatesState>(INITIAL_STATE);
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
            source(moduleCode, {
                ...(keyword === '' ? {} : { q: keyword }),
                ...(excludedId === null ? {} : { exclude: excludedId }),
            }),
            {
                onSuccess: (result) => {
                    if (isLatest()) {
                        setState({
                            result,
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
    }, [keyword, moduleCode, excludedId, searchesEmpty, source]);

    return state;
}
