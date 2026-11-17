import { describe, expect, it, vi } from 'vite-plus/test';
import { focusBackOnClose } from './dialogFocus';

/** focus だけを持つ要素の代わり(isConnected = DOM に残っているか) */
function element(isConnected: boolean) {
    return { isConnected, focus: vi.fn() };
}

function closeWith(target: ReturnType<typeof element> | null) {
    const event = { preventDefault: vi.fn() };
    const ref = { current: target as unknown as HTMLElement | null };

    focusBackOnClose(ref)(event as unknown as Event);

    return event;
}

describe('focusBackOnClose', () => {
    it('既定の戻し先を止め、DOM に残っている要素へフォーカスを戻す', () => {
        const target = element(true);
        const event = closeWith(target);

        expect(event.preventDefault).toHaveBeenCalledOnce();
        expect(target.focus).toHaveBeenCalledOnce();
    });

    it('要素が DOM から消えていれば、既定を止めてフォーカスを動かさない', () => {
        const target = element(false);
        const event = closeWith(target);

        expect(event.preventDefault).toHaveBeenCalledOnce();
        expect(target.focus).not.toHaveBeenCalled();
    });

    it('ref が要素を持たなければ、既定を止めるだけ', () => {
        expect(closeWith(null).preventDefault).toHaveBeenCalledOnce();
    });
});
