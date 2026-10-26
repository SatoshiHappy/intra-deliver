import { useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { initialProxyForm, proxyPayloadOf } from '@/lib/Workflow/proxyForm';
import type { ProxyForm } from '@/lib/Workflow/proxyForm';
import { store as adminStore } from '@/routes/workflow/admin/proxies';
import { store } from '@/routes/workflow/proxies';

/** 代理を追加するダイアログの入力の状態(誤りのキーは入力の名前のまま引く) */
export type ProxyFormState = {
    form: InertiaFormProps<ProxyForm>;
    errors: Record<string, string | undefined>;
    submit: (event: FormEvent) => void;
};

/**
 * 代理を追加するダイアログの入力と送信(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節)。
 * 送り先は代理の設定か代理の管理(isAdmin)。成功したら onSucceeded(サーバーのリダイレクトとトースト)、誤りはダイアログの欄の下に出す。
 */
export function useProxyForm(
    isAdmin: boolean,
    today: string,
    onSucceeded: () => void,
): ProxyFormState {
    const form = useForm<ProxyForm>(initialProxyForm(today));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => proxyPayloadOf(data, isAdmin));
        form.submit(isAdmin ? adminStore() : store(), {
            preserveScroll: true,
            onSuccess: onSucceeded,
        });
    };

    return {
        form,
        errors: form.errors as Record<string, string | undefined>,
        submit,
    };
}
