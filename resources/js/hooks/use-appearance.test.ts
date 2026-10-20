import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { getStoredAppearance } from './use-appearance';

function stubStorage(getItem: (key: string) => string | null): void {
    vi.stubGlobal('window', {});
    vi.stubGlobal('localStorage', { getItem });
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('getStoredAppearance', () => {
    it('保存した値を返す', () => {
        stubStorage((key) => (key === 'appearance' ? 'dark' : null));

        expect(getStoredAppearance()).toBe('dark');
    });

    it('保存した値が無ければ system', () => {
        stubStorage(() => null);

        expect(getStoredAppearance()).toBe('system');
    });

    it('保存先が読めなければ system(起動を止めない)', () => {
        stubStorage(() => {
            throw new Error('SecurityError');
        });

        expect(getStoredAppearance()).toBe('system');
    });

    it('window が無い(サーバーの描画)なら system', () => {
        expect(getStoredAppearance()).toBe('system');
    });
});
