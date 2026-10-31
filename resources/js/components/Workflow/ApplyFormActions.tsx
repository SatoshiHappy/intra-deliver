import { ApplyActions } from '@/components/Workflow/ApplyActions';
import type { useApplyForm } from '@/hooks/Workflow/use-apply-form';
import {
    applyActionOf,
    backHrefOf,
    draftActionOf,
} from '@/lib/Workflow/applyRoutes';
import type { applyViewOf } from '@/lib/Workflow/applyScreen';
import type { WorkflowApplyProps } from '@/types';

/**
 * 申請の画面のボタン列に、画面の文・送り先・申請対象の値を取っている間の印を渡す(送る内容は useApplyForm の send)。
 */
export function ApplyFormActions({
    props,
    view,
    apply,
}: {
    props: WorkflowApplyProps;
    view: ReturnType<typeof applyViewOf>;
    apply: ReturnType<typeof useApplyForm>;
}) {
    return (
        <ApplyActions
            mode={props.mode}
            workflowCase={props.case}
            isProcessing={apply.form.processing}
            backHref={backHrefOf(props)}
            operation={view.operation}
            confirmDescription={[
                `${view.caseName}を${view.operation}します。`,
                ...view.nextLine,
            ]}
            isApplyDisabled={view.blocker !== null}
            isFetching={apply.applicationTarget.isBusy}
            onSaveDraft={() => apply.send(draftActionOf(props), 'saveDraft')}
            onApply={() => apply.send(applyActionOf(props), 'apply')}
        />
    );
}
