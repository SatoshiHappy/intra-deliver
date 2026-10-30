import { Dialog, DialogContent } from '@/components/ui/dialog';
import { CaseOperationForm } from '@/components/Workflow/CaseOperationForm';
import type { OperationDialogProps } from '@/hooks/Workflow/use-operation-dialog';

/**
 * 申請操作のダイアログ(処理担当者の変更・再展開。docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節)。
 * 処理のダイアログと同じく描き分けの外(ページの直下)に 1 つ置き、開いた時の対象の写しだけを読む。閉じた後は、押したボタンが残っていればフォーカスを戻す。
 */
export function CaseOperationDialog({
    open,
    target,
    openCount,
    returnFocusTo,
    onClose,
    onSucceeded,
}: OperationDialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <DialogContent
                className="flex max-h-[90vh] flex-col"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();

                    if (returnFocusTo.current?.isConnected === true) {
                        returnFocusTo.current.focus();
                    }
                }}
            >
                {target !== null && (
                    <CaseOperationForm
                        key={openCount}
                        target={target}
                        openCount={openCount}
                        onSucceeded={onSucceeded}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
