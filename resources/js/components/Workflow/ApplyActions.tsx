import { Link } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { FormActions } from '@/components/FormLayout';
import { Button } from '@/components/ui/button';
import { ApplyConfirmDialog } from '@/components/Workflow/ApplyConfirmDialog';
import { showsSaveDraft } from '@/lib/Workflow/applyScreen';
import { cn } from '@/lib/utils';
import { destroy as draftDestroy } from '@/routes/workflow/drafts';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/**
 * 申請の画面のボタン列(キャンセル・一時保存の削除・一時保存・申請/再申請の確認)。送信中はまとめて押せない。
 * 一時保存のボタンは {@link showsSaveDraft} が出す画面だけ、削除は一時保存の画面だけに出す。
 */
export function ApplyActions({
    mode,
    workflowCase,
    isProcessing,
    backHref,
    operation,
    confirmDescription,
    isApplyDisabled,
    onSaveDraft,
    onApply,
}: {
    mode: WorkflowApplyProps['mode'];
    workflowCase: WorkflowApplyProps['case'];
    isProcessing: boolean;
    backHref: RouteDefinition<'get'>;
    operation: string;
    confirmDescription: string;
    isApplyDisabled: boolean;
    onSaveDraft: () => void;
    onApply: () => void;
}) {
    return (
        <fieldset disabled={isProcessing}>
            <FormActions>
                <Button variant="outline" asChild>
                    <Link
                        href={backHref}
                        aria-disabled={isProcessing || undefined}
                        className={cn(
                            isProcessing && 'pointer-events-none opacity-50',
                        )}
                    >
                        キャンセル
                    </Link>
                </Button>
                {mode === 'draft' && workflowCase !== null && (
                    <ConfirmDeleteDialog
                        triggerText="一時保存を削除"
                        triggerVariant="destructive"
                        title="一時保存の削除"
                        description="この一時保存を削除しますか。この操作は取り消せません。"
                        action={draftDestroy(workflowCase.id)}
                        data={{ revision: workflowCase.revision }}
                    />
                )}
                {showsSaveDraft(mode) && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onSaveDraft}
                    >
                        一時保存
                    </Button>
                )}
                <ApplyConfirmDialog
                    label={operation}
                    description={confirmDescription}
                    disabled={isProcessing || isApplyDisabled}
                    onConfirm={onApply}
                />
            </FormActions>
        </fieldset>
    );
}
