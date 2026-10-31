import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { store } from '@/routes/workflow/admin/versions';

/**
 * 版を追加するダイアログの入力と送信(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 4 節)。
 * 開始日の誤りはキー start_date。成功したら onSucceeded(サーバーのリダイレクトとトースト)。
 */
export function useVersionAddForm(
    flow: string,
    initialStartDate: string,
    onSucceeded: () => void,
) {
    const form = useForm({ start_date: initialStartDate });

    return {
        startDate: form.data.start_date,
        error: form.errors.start_date,
        isProcessing: form.processing,
        setStartDate: (value: string) => form.setData('start_date', value),
        submit: (event: FormEvent) => {
            event.preventDefault();
            form.submit(store(flow), {
                preserveScroll: true,
                onSuccess: onSucceeded,
            });
        },
    };
}
