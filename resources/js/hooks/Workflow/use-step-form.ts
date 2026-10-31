import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { clearRowErrors, clearServerErrors } from '@/lib/formErrors';
import { errorKeysOf, formOf, payloadOf } from '@/lib/Workflow/stepForm';
import type { StepForm } from '@/lib/Workflow/stepForm';
import { update } from '@/routes/workflow/admin/nodes';
import type { WorkflowStepSettings } from '@/types';

/**
 * ステップの設定のカードの入力と送信(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節)。
 * 保存は 1 つのステップの全カードを 1 回で送る(送っている間は押せない)。誤りのキーは欄の名前(snake_case)で、
 * 欄を変えたらその欄の誤りを消す。
 */
export function useStepForm(
    flowCode: string,
    version: number,
    step: WorkflowStepSettings,
) {
    const form = useForm<StepForm>(formOf(step));

    return {
        values: form.data,
        errors: form.errors as Record<string, string | undefined>,
        isProcessing: form.processing,
        set: <K extends keyof StepForm>(field: K, value: StepForm[K]) => {
            if (field === 'rows') {
                clearRowErrors(form, 'targets');
            } else {
                clearServerErrors(form, errorKeysOf(field));
            }

            form.setData(
                (current) => ({ ...current, [field]: value }) as StepForm,
            );
        },
        submit: (event: FormEvent) => {
            event.preventDefault();
            form.transform((data) => payloadOf(data, step));
            form.submit(update({ flow: flowCode, version, node: step.code }), {
                preserveScroll: true,
            });
        },
    };
}

/** 入力の状態と操作(カードの部品が受け取る) */
export type StepFormApi = ReturnType<typeof useStepForm>;
