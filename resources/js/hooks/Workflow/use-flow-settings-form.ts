import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { flowSettingsFormOf } from '@/lib/Workflow/flowSettingsForm';
import { updateSettings } from '@/routes/workflow/admin/flows';

/**
 * タブ「設定」の面「添付ファイル」の入力と送信(docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節)。
 * キャンセルは今の値(サーバーの値)に戻す。保存は面ごとで、真偽を 1 つ送る。
 */
export function useFlowSettingsForm(flow: string, saved: boolean) {
    const form = useForm(flowSettingsFormOf(saved));

    return {
        isAttachmentEnabled: form.data.is_attachment_enabled,
        errors: form.errors as Record<string, string | undefined>,
        isProcessing: form.processing,
        change: (isAttachmentEnabled: boolean) => {
            form.clearErrors();
            form.setData('is_attachment_enabled', isAttachmentEnabled);
        },
        cancel: () => {
            form.clearErrors();
            form.setData(flowSettingsFormOf(saved));
        },
        submit: (event: FormEvent) => {
            event.preventDefault();
            form.submit(updateSettings(flow), { preserveScroll: true });
        },
    };
}
