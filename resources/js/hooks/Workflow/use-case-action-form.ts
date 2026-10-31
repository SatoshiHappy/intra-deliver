import { useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useCaseActionAttachment } from '@/hooks/Workflow/use-case-action-attachment';
import { sendCaseAction } from '@/hooks/Workflow/use-case-action-send';
import { clearFileErrors } from '@/lib/Workflow/applyAttachment';
import { attachableOf } from '@/lib/Workflow/caseActionAttach';
import type { PageAttach } from '@/lib/Workflow/caseActionAttach';
import { initialFormOf, nodeOf } from '@/lib/Workflow/caseActionForm';
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
    attachment: ReturnType<typeof useCaseActionAttachment> & {
        attachable: boolean;
        limits: PageAttach['limits'];
    };
    submit: (event: FormEvent) => void;
};

/**
 * 処理のダイアログの入力と送信(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節)。初期値は開いた時の写しから作り、
 * 成功したら onSucceeded に開いた回の番号を渡す。指定の欄を持つのは承認だけ。添付は選んだ入口の可否(ページの今の props = attach)で
 * 出して送り、ファイルがあれば sendShapeOf が multipart にする(送る前の確かめと 413 のトーストは申請の画面と同じ関数)。
 */
export function useCaseActionForm(
    target: CaseActionTarget,
    openCount: number,
    onSucceeded: (count: number) => void,
    attach: PageAttach,
): CaseActionFormState {
    const form = useForm<ActionForm>(initialFormOf(target));
    const node = nodeOf(target.nodes, form.data.node_id);
    const assigneeFields =
        target.operation === 'approve' ? (node?.assigneeFields ?? []) : [];
    const attachable = attachableOf(attach, target, node, form.data.principal);
    const attachment = useCaseActionAttachment(attachable, () =>
        clearFileErrors(form),
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        sendCaseAction(
            {
                form,
                target,
                assigneeFields,
                files: attachment.files,
                limits: attach.limits,
                onSuccess: () => onSucceeded(openCount),
            },
            ACTION_ROUTES[target.operation](target.caseId),
        );
    };

    return {
        form,
        errors: form.errors as Record<string, string | undefined>,
        node,
        assigneeFields,
        attachment: { ...attachment, attachable, limits: attach.limits },
        submit,
    };
}
