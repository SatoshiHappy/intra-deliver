import { describe, expect, it, vi } from 'vite-plus/test';
import { toggleFullscreen } from './use-diagram-fullscreen';
import type { FullscreenDocument } from './use-diagram-fullscreen';

/** 全画面を求められたかを覚える偽物の図の要素 */
function fakeElement() {
    const requestFullscreen = vi.fn(() => Promise.resolve());

    return {
        element: { requestFullscreen } as unknown as HTMLElement,
        requestFullscreen,
    };
}

/** fullscreenElement が shown の偽物の document */
function fakeDocument(shown: Element | null) {
    const exitFullscreen = vi.fn(() => Promise.resolve());
    const doc: FullscreenDocument = {
        fullscreenElement: shown,
        exitFullscreen,
    };

    return { doc, exitFullscreen };
}

describe('toggleFullscreen(広げて表示の切り替え)', () => {
    it('全画面でなければ、図の要素を全画面にする', async () => {
        const { element, requestFullscreen } = fakeElement();
        const { doc, exitFullscreen } = fakeDocument(null);

        await toggleFullscreen(element, doc);

        expect(requestFullscreen).toHaveBeenCalledOnce();
        expect(exitFullscreen).not.toHaveBeenCalled();
    });

    it('図が全画面なら、元の大きさに戻す', async () => {
        const { element, requestFullscreen } = fakeElement();
        const { doc, exitFullscreen } = fakeDocument(element);

        await toggleFullscreen(element, doc);

        expect(exitFullscreen).toHaveBeenCalledOnce();
        expect(requestFullscreen).not.toHaveBeenCalled();
    });

    it('別の要素が全画面なら、図の要素を全画面にする', async () => {
        const { element, requestFullscreen } = fakeElement();
        const other = fakeElement().element;
        const { doc, exitFullscreen } = fakeDocument(other);

        await toggleFullscreen(element, doc);

        expect(requestFullscreen).toHaveBeenCalledOnce();
        expect(exitFullscreen).not.toHaveBeenCalled();
    });

    it('要素がまだ無ければ何もしない', async () => {
        const { doc, exitFullscreen } = fakeDocument(null);

        await toggleFullscreen(null, doc);

        expect(exitFullscreen).not.toHaveBeenCalled();
    });

    it('ブラウザが拒んだら、その失敗を返す(握りつぶさない)', async () => {
        const element = {
            requestFullscreen: () => Promise.reject(new TypeError('denied')),
        } as unknown as HTMLElement;

        await expect(
            toggleFullscreen(element, fakeDocument(null).doc),
        ).rejects.toThrow('denied');
    });
});
