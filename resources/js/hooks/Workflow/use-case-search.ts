import { router } from '@inertiajs/react';
import { useEffect, useEffectEvent, useState } from 'react';
import { caseListUrl, visitCaseList } from '@/lib/Workflow/caseListUrl';
import type { CaseListQuery } from '@/lib/Workflow/caseListUrl';

/** 最後の入力から一覧を引き直すまでの待ち(入力の停止。docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節) */
const DEBOUNCE_MS = 300;

/**
 * 申請一覧の検索の欄の語と待ち。入力の停止(DEBOUNCE_MS)か Enter(submit)で、今のタブ・切替の一覧を語で引き直す。
 * タブ・切替・ページ番号の URL にも欄の語を入れ、ほかの移動が始まったら待ちを消す(同期の訪問どうしは後の訪問が前を中断するので、
 * 待ちの発火で押したタブを消さないため)。
 */
export function useCaseSearch(query: CaseListQuery) {
    const [value, setValue] = useState(query.q);
    const [waiting, setWaiting] = useState<string | null>(null);
    const search = (q: string) =>
        visitCaseList(caseListUrl({ ...query, q }), true);

    const fire = useEffectEvent((q: string) => {
        setWaiting(null);
        search(q);
    });

    useEffect(() => {
        if (waiting === null) {
            return;
        }

        const timer = window.setTimeout(() => fire(waiting), DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [waiting]);

    useEffect(() => router.on('start', () => setWaiting(null)), []);

    return {
        value,
        change: (next: string) => {
            setValue(next);
            setWaiting(next);
        },
        submit: () => {
            setWaiting(null);
            search(value);
        },
    };
}
