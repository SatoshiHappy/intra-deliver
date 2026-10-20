import { useForm } from '@inertiajs/react';
import { EDITED_PROPS } from '@/lib/Authz/editMode';
import { destroy } from '@/routes/authz/editor/subjects';
import type { AuthzGridSubject } from '@/types';

/**
 * 列の削除の送信(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.2)。列の props の件数と指紋をそのまま送り返す
 * (サーバーが取り直した値と違えば 422 subject)。誤りは返して閉じない。成功したら onDone を呼ぶ。
 * 件数と指紋が無い列(削除できない人の props)は送れない(canRemove が偽)。
 */
export function useDeleteColumn(subject: AuthzGridSubject, onDone: () => void) {
    const form = useForm({});
    const { policyCount, fingerprint } = subject;

    return {
        errors: form.errors,
        processing: form.processing,
        canRemove: policyCount !== null && fingerprint !== null,
        clearErrors: () => form.clearErrors(),
        remove: () => {
            form.transform(() => ({ count: policyCount, fingerprint }));
            form.submit(destroy(subject.id), {
                only: EDITED_PROPS,
                preserveScroll: true,
                preserveState: true,
                onSuccess: onDone,
            });
        },
    };
}
