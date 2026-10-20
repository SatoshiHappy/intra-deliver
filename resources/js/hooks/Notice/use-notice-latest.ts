import { useHttp } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { latest } from '@/routes/notice';
import type { NoticeLatestResult, NoticeRow } from '@/types';

/**
 * 鈴の中の最新 10 件の状態。waiting = 要求を待っている(前に開いたときの行は持たない)、failed = 要求が失敗した、
 * loaded = 最後に採った行(0 件もある)。
 */
export type NoticeLatestState =
    | { status: 'waiting' }
    | { status: 'failed' }
    | { status: 'loaded'; rows: NoticeRow[] };

const WAITING: NoticeLatestState = { status: 'waiting' };

/**
 * 鈴の最新 10 件(docs の 設計/ワークフロー/案件の運用/通知.md の 7 節)。load を鈴を開くたびに呼ぶと、状態を待ちへ戻して
 * 最新 10 件の口へ useHttp で要求を送る(部分の再読み込みは今の画面のコントローラを走らせるので使わない)。
 *
 * 前の要求は中止しない(useHttp は要求ごとの中止を持たない)。要求ごとの連番で最新の要求の結果だけを採り、遅れて届いた古い応答は捨てる。
 * フックの response・processing は、後から終わった要求が書き換えるので使わない。失敗は onError(422)・onHttpException(403・5xx)・
 * onNetworkError から作る(前例 = use-master-search.ts)。
 */
export function useNoticeLatest() {
    const http = useHttp<Record<string, never>, NoticeLatestResult>({});
    const latestRequest = useRef(0);
    const [state, setState] = useState<NoticeLatestState>(WAITING);

    function load() {
        const request = ++latestRequest.current;
        const settle = (next: NoticeLatestState) => {
            if (request === latestRequest.current) {
                setState(next);
            }
        };
        const fail = () => settle({ status: 'failed' });

        setState(WAITING);
        http.get(latest.url(), {
            onSuccess: (result) =>
                settle({ status: 'loaded', rows: result.rows }),
            onError: fail,
            onHttpException: fail,
            onNetworkError: fail,
        }).catch(() => {
            // 失敗は上の onHttpException・onNetworkError で状態にした(get の Promise はそれらの後に reject する)。
        });
    }

    return { state, load };
}
