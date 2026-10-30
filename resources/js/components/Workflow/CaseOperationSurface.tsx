import type { MouseEvent } from 'react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { Button } from '@/components/ui/button';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import type { OpenCaseOperation } from '@/hooks/Workflow/use-operation-dialog';
import {
    operationTargetOf,
    releaseTargetOf,
} from '@/lib/Workflow/caseOperations';
import {
    DELETE_CONFIRM_TEXT,
    OPERATION_LABELS,
    OPERATION_SURFACE_NOTE,
} from '@/lib/Workflow/caseOperationTexts';
import type { OperationKind } from '@/lib/Workflow/caseOperationTexts';
import { destroy } from '@/routes/workflow/admin/cases';
import type {
    WorkflowCaseOperationPanel,
    WorkflowCaseShowProps,
} from '@/types';

type Props = Pick<
    WorkflowCaseShowProps,
    'case' | 'nodeActions' | 'commentMaxLength'
> & {
    operation: WorkflowCaseOperationPanel;
    onOpenOperation: OpenCaseOperation;
    onOpenAction: OpenCaseAction;
};

/**
 * 面のボタン 1 つ(押すとダイアログが開く)。
 */
function OperationButton({
    label,
    onOpen,
}: {
    label: string;
    onOpen: (button: HTMLElement) => void;
}) {
    return (
        <Button
            type="button"
            variant="outline"
            aria-haspopup="dialog"
            onClick={(event: MouseEvent<HTMLButtonElement>) =>
                onOpen(event.currentTarget)
            }
        >
            {label}
        </Button>
    );
}

/** ダイアログを開くボタンの並び(面の並びのまま。保留解除と申請の削除はこの後) */
const DIALOG_KINDS: OperationKind[] = [
    'retarget',
    'reexpand',
    'move',
    'assign',
];

/**
 * 保留解除のボタン。面の releaseNodes のステップを対象に、処理のダイアログ(保留を解除する)を開く。
 */
function ReleaseButton({
    operation,
    onOpenAction,
    ...show
}: Omit<Props, 'onOpenOperation'>) {
    const release = releaseTargetOf({
        caseId: show.case.id,
        revision: show.case.revision,
        commentMaxLength: show.commentMaxLength,
        operation,
        caseName: operation.caseName,
        nodeActions: show.nodeActions,
    });

    return (
        <OperationButton
            label={OPERATION_LABELS.release}
            onOpen={(button) => onOpenAction(release, button)}
        />
    );
}

/**
 * ボタンの並び(許されるものだけ = operation.buttons。完了の申請は「申請の削除」だけ)。申請の削除は確かめのダイアログ。
 */
function OperationButtons(props: Props) {
    const { operation, onOpenOperation, case: workflowCase } = props;
    const { buttons } = operation;
    const source = {
        caseId: workflowCase.id,
        revision: workflowCase.revision,
        commentMaxLength: props.commentMaxLength,
        operation,
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            {DIALOG_KINDS.filter((kind) => buttons[kind]).map((kind) => (
                <OperationButton
                    key={kind}
                    label={OPERATION_LABELS[kind]}
                    onOpen={(button) =>
                        onOpenOperation(operationTargetOf(kind, source), button)
                    }
                />
            ))}
            {buttons.release && <ReleaseButton {...props} />}
            {buttons.destroy && (
                <ConfirmDeleteDialog
                    triggerText={OPERATION_LABELS.destroy}
                    triggerVariant="destructive"
                    title={OPERATION_LABELS.destroy}
                    description={DELETE_CONFIRM_TEXT}
                    action={destroy(workflowCase.id)}
                    data={{ revision: workflowCase.revision }}
                />
            )}
        </div>
    );
}

/**
 * 詳細の申請操作の面(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節。詳細の面の最後): 見出し・1 行・ボタンの並び。
 */
export function CaseOperationSurface(props: Props) {
    return (
        <section
            aria-labelledby="case-operation-heading"
            className="flex flex-col gap-3 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <h2
                id="case-operation-heading"
                className="text-[15px] font-semibold"
            >
                申請操作
            </h2>
            <p className="text-sm text-muted-foreground">
                {OPERATION_SURFACE_NOTE}
            </p>
            <OperationButtons {...props} />
        </section>
    );
}
