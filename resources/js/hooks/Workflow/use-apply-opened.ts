import { router, useRemember } from '@inertiajs/react';
import { useEffect } from 'react';
import {
    openedOf,
    rememberKeyOf,
    strippedUrlOf,
    withOpened,
} from '@/lib/Workflow/applyOpened';
import type { ClearedMark } from '@/lib/clearedFields';
import type { WorkflowApplyProps } from '@/types';

/**
 * 申請の画面の開いた時の props を保つ(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「写しのためだけのクエリ」)。
 * 戻る・進むでも戻るよう、履歴の項目に覚える(Inertia の remember。再読み込みでは消える)。
 */
export function useApplyOpened(props: WorkflowApplyProps): {
    props: WorkflowApplyProps;
    copyMarks: ClearedMark[];
} {
    const [opened] = useRemember(
        openedOf(props),
        `${rememberKeyOf(props)}:opened`,
    );
    const shown = withOpened(props, opened);

    return {
        props: shown,
        copyMarks: shown.copy === null ? [] : (opened.copyMarks ?? []),
    };
}

/**
 * 写しのためだけのクエリを開いた直後に URL から外す(同じ 5 節。履歴を置き換え、props と入力はそのまま)。
 * 置き換えは呼んだ時の履歴の項目の写しを後から書き戻すので、覚える effect のすべてより後に呼ぶ(先だと覚えた値が消える)。
 */
export function useStripCopyQueries() {
    useEffect(() => {
        const url = strippedUrlOf(window.location.href);

        if (url !== null) {
            router.replace({ url, preserveState: true, preserveScroll: true });
        }
    }, []);
}
