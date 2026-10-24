import { useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    initialFormOf,
    nodeOf,
    payloadOf,
} from '@/lib/Workflow/caseActionForm';
import type { ActionForm } from '@/lib/Workflow/caseActionForm';
import type {
    CaseActionNode,
    CaseActionTarget,
    CaseOperation,
} from '@/lib/Workflow/caseActions';
import {
    approve,
    approveEarly,
    hold,
    pullBack,
    reject,
    release,
    sendBack,
    transfer,
    withdraw,
} from '@/routes/workflow/cases';
import type { WorkflowDesignation } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/** 処理ごとの送り先(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節の表) */
const ACTION_ROUTES: Record<
    CaseOperation,
    (caseId: number) => RouteDefinition<'post'>
> = {
    approve,
    approveEarly,
    reject,
    sendBack,
    hold,
    release,
    transfer,
    pullBack,
    withdraw,
};

/** 処理のダイアログの入力の状態(誤りのキーは dotted のまま引く) */
export type CaseActionFormState = {
    form: InertiaFormProps<ActionForm>;
    errors: Record<string, string | undefined>;
    node: CaseActionNode | undefined;
    assigneeFields: WorkflowDesignation[];
    submit: (event: FormEvent) => void;
};

/**
 * 処理のダイアログの入力と送信(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節)。初期値は開いた時の写しから作り、
 * 成功したら onSucceeded に開いた回の番号を渡す。指定の欄を持つのは承認だけ。
 */
export function useCaseActionForm(
    target: CaseActionTarget,
    openCount: number,
    onSucceeded: (count: number) => void,
): CaseActionFormState {
    const form = useForm<ActionForm>(initialFormOf(target));
    const node = nodeOf(target.nodes, form.data.node_id);
    const assigneeFields =
        target.operation === 'approve' ? (node?.assigneeFields ?? []) : [];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => payloadOf(data, target, assigneeFields));
        form.submit(ACTION_ROUTES[target.operation](target.caseId), {
            preserveScroll: true,
            onSuccess: () => onSucceeded(openCount),
        });
    };

    return {
        form,
        errors: form.errors as Record<string, string | undefined>,
        node,
        assigneeFields,
        submit,
    };
}
