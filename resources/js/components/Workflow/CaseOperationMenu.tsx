import { ChevronDown } from 'lucide-react';
import { useRef, useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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

/** ダイアログを開く項目の並び(保留解除と申請の削除はこの後) */
const DIALOG_KINDS: OperationKind[] = [
    'retarget',
    'reexpand',
    'move',
    'assign',
];

/** 「申請操作」のボタンを開いた要素として渡し、ダイアログを開く */
type OpenFrom = (open: (button: HTMLElement) => void) => () => void;

/** ダイアログを開く項目(申請操作のダイアログと、保留解除 = 処理のダイアログ) */
function DialogItems({
    operation,
    onOpenOperation,
    onOpenAction,
    openFrom,
    ...show
}: Props & { openFrom: OpenFrom }) {
    const source = {
        caseId: show.case.id,
        revision: show.case.revision,
        commentMaxLength: show.commentMaxLength,
        operation,
    };
    const release = () =>
        releaseTargetOf({
            ...source,
            caseName: operation.caseName,
            nodeActions: show.nodeActions,
        });

    return (
        <>
            {DIALOG_KINDS.filter((kind) => operation.buttons[kind]).map(
                (kind) => (
                    <DropdownMenuItem
                        key={kind}
                        onSelect={openFrom((button) =>
                            onOpenOperation(
                                operationTargetOf(kind, source),
                                button,
                            ),
                        )}
                    >
                        {OPERATION_LABELS[kind]}
                    </DropdownMenuItem>
                ),
            )}
            {operation.buttons.release && (
                <DropdownMenuItem
                    onSelect={openFrom((button) =>
                        onOpenAction(release(), button),
                    )}
                >
                    {OPERATION_LABELS.release}
                </DropdownMenuItem>
            )}
        </>
    );
}

/** メニューの中身(項目の並び = docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節) */
function OperationMenuContent({
    onDelete,
    ...props
}: Props & { openFrom: OpenFrom; onDelete: () => void }) {
    return (
        <DropdownMenuContent align="start" side="top">
            <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                {OPERATION_SURFACE_NOTE}
            </DropdownMenuLabel>
            <DialogItems {...props} />
            {props.operation.buttons.destroy && (
                <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onSelect={onDelete}>
                        {OPERATION_LABELS.destroy}
                    </DropdownMenuItem>
                </>
            )}
        </DropdownMenuContent>
    );
}

/**
 * 帯の左端の「申請操作」のボタンとメニュー(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節)。
 * メニューを modal にしないのは、項目を押して閉じた直後にダイアログを開くため(ダイアログはメニューが閉じても残る外に置く)。
 */
export function CaseOperationMenu(props: Props) {
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [deleting, setDeleting] = useState(false);
    const openFrom: OpenFrom = (open) => () => {
        if (triggerRef.current !== null) {
            open(triggerRef.current);
        }
    };

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button ref={triggerRef} type="button" variant="outline">
                        申請操作
                        <ChevronDown aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <OperationMenuContent
                    {...props}
                    openFrom={openFrom}
                    onDelete={() => setDeleting(true)}
                />
            </DropdownMenu>
            {props.operation.buttons.destroy && (
                <ConfirmDeleteDialog
                    open={deleting}
                    onOpenChange={setDeleting}
                    returnFocusTo={triggerRef}
                    title={OPERATION_LABELS.destroy}
                    description={DELETE_CONFIRM_TEXT}
                    action={destroy(props.case.id)}
                    data={{ revision: props.case.revision }}
                />
            )}
        </>
    );
}
