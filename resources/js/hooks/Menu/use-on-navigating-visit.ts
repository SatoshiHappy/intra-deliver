import { router } from '@inertiajs/react';
import { useEffect, useEffectEvent } from 'react';
import { isNavigatingVisit } from '@/lib/Menu/visit';

/**
 * 画面を移る訪問が始まったときに onVisit を呼ぶ(同じ画面に留まる訪問では呼ばない。判定は isNavigatingVisit)。
 * サイドバーのシートとランチャーのパネルを閉じるのに使う。
 */
export function useOnNavigatingVisit(onVisit: () => void): void {
    const handleVisit = useEffectEvent(onVisit);

    useEffect(
        () =>
            router.on('start', (event) => {
                if (
                    isNavigatingVisit(
                        event.detail.visit,
                        new URL(window.location.href),
                    )
                ) {
                    handleVisit();
                }
            }),
        [],
    );
}
