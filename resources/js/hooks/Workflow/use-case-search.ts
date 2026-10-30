import { router } from '@inertiajs/react';
import { useEffect, useEffectEvent, useState } from 'react';

/** 最後の入力から一覧を引き直すまでの待ち(入力の停止。docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節) */
const DEBOUNCE_MS = 300;

/**
 * 一覧の検索の欄の語と待ち(申請一覧と申請操作の一覧が使う)。入力の停止(DEBOUNCE_MS)か Enter(submit)で、今の絞り込みの一覧を語で
 * 引き直す(search が引き直し。履歴を積まない)。呼び手はタブ・切替・ページ番号の URL にも欄の語を入れ、ほかの移動が始まったら
 * 待ちを消す(同期の訪問どうしは後の訪問が前を中断するので、待ちの発火で押したタブを消さないため)。
 */
export function useCaseSearch(initial: string, search: (q: string) => void) {
    const [value, setValue] = useState(initial);
    const [waiting, setWaiting] = useState<string | null>(null);

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
