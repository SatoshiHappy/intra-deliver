import { useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    initialOperationForm,
    operationPayloadOf,
} from '@/lib/Workflow/caseOperations';
import type {
    OperationForm,
    OperationTarget,
} from '@/lib/Workflow/caseOperations';
import {
    assign,
    move,
    reexpand,
    retarget,
} from '@/routes/workflow/admin/cases';

/** 種類ごとの送り先(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節の表) */
const ROUTES = { retarget, reexpand, move, assign };

/** 申請操作のダイアログの入力の状態(誤りのキーは入力の名前のまま引く) */
export type OperationFormState = {
    form: InertiaFormProps<OperationForm>;
    errors: Record<string, string | undefined>;
    submit: (event: FormEvent) => void;
};

/**
 * 申請操作のダイアログの入力と送信(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節、送り先 = 画面/README.md の 1.1 節)。
 * 初期値は開いた時の対象から作り、成功したら onSucceeded に開いた回の番号を渡す(移り先とトーストはサーバーのリダイレクト)。
 */
export function useOperationForm(
    target: OperationTarget,
    openCount: number,
    onSucceeded: (count: number) => void,
): OperationFormState {
    const form = useForm<OperationForm>(initialOperationForm(target));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => operationPayloadOf(data, target));
        form.submit(ROUTES[target.kind](target.caseId), {
            preserveScroll: true,
            onSuccess: () => onSucceeded(openCount),
        });
    };

    return {
        form,
        errors: form.errors as Record<string, string | undefined>,
        submit,
    };
}
