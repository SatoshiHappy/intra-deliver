import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { CaseOperationDialog } from '@/components/Workflow/CaseOperationDialog';
import { CaseOperationSurface } from '@/components/Workflow/CaseOperationSurface';
import { useOperationDialog } from '@/hooks/Workflow/use-operation-dialog';
import type { WorkflowCaseShowProps } from '@/types';

/**
 * 詳細の申請操作(面とダイアログ)。面は operation があるときだけ出し、ダイアログは面の有無の外(いつも描く)に 1 つ置く
 * (誤りの後に面が消えても閉じない = 処理のダイアログと同じ)。保留解除は詳細の処理のダイアログ(onOpenAction)を開く。
 */
export function CaseOperationArea({
    onOpenAction,
    ...show
}: Pick<
    WorkflowCaseShowProps,
    'case' | 'nodeActions' | 'commentMaxLength' | 'operation'
> & { onOpenAction: OpenCaseAction }) {
    const { openOperation, dialogProps } = useOperationDialog();
    const { operation, ...rest } = show;

    return (
        <>
            {operation !== null && (
                <CaseOperationSurface
                    {...rest}
                    operation={operation}
                    onOpenOperation={openOperation}
                    onOpenAction={onOpenAction}
                />
            )}
            <CaseOperationDialog {...dialogProps} />
        </>
    );
}
