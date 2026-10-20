import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/**
 * 検証のエラー(サーバーの日本語の文言)を、項目名に依らず文言のままトーストで出す。
 */
export function toastErrors(errors: Record<string, string>): void {
    Object.values(errors).forEach((message) => toast.error(message));
}

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        toast[data.type](data.message);
    });
}
