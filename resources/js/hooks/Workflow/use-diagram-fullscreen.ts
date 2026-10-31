import { useCallback, useState, useSyncExternalStore } from 'react';

/** 全画面の切り替えに使う document の部分(テストでは偽物を渡す) */
export type FullscreenDocument = Pick<
    Document,
    'fullscreenElement' | 'exitFullscreen'
>;

/**
 * 「広げて表示」の切り替え: 図が全画面なら元に戻し、そうでなければ図だけを全画面にする(ブラウザの Fullscreen API。
 * Esc でもブラウザが元に戻す)。要求が拒まれたときの Promise はそのまま返す(握りつぶさない)。
 */
export function toggleFullscreen(
    element: HTMLElement | null,
    doc: FullscreenDocument,
): Promise<void> {
    if (element === null) {
        return Promise.resolve();
    }

    if (doc.fullscreenElement === element) {
        return doc.exitFullscreen();
    }

    return element.requestFullscreen();
}

function subscribeFullscreen(onChange: () => void): () => void {
    document.addEventListener('fullscreenchange', onChange);

    return () => document.removeEventListener('fullscreenchange', onChange);
}

function subscribeNothing(): () => void {
    return () => {};
}

/**
 * 図を全画面で見る操作(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節)。
 * elementRef を全画面にする要素に渡す。isEnabled = ブラウザが要素の全画面を許すか(許さなければ操作を出さない)。
 */
export function useDiagramFullscreen() {
    const [element, setElement] = useState<HTMLDivElement | null>(null);
    const fullscreenElement = useSyncExternalStore(
        subscribeFullscreen,
        () => document.fullscreenElement,
        () => null,
    );
    const isEnabled = useSyncExternalStore(
        subscribeNothing,
        () => document.fullscreenEnabled,
        () => false,
    );
    const toggle = useCallback(() => {
        void toggleFullscreen(element, document);
    }, [element]);

    return {
        elementRef: setElement,
        isFullscreen: element !== null && fullscreenElement === element,
        isEnabled,
        toggle,
    };
}

/** 全画面の状態と切り替え(useDiagramFullscreen の返り値) */
export type DiagramFullscreen = ReturnType<typeof useDiagramFullscreen>;
