import { Link } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { Button } from '@/components/ui/button';
import { CaseActionButton } from '@/components/Workflow/CaseActionDialog';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { pullBackNodesOf } from '@/lib/Workflow/caseActions';
import type {
    CaseActionTarget,
    CaseOperation,
} from '@/lib/Workflow/caseActions';
import { show } from '@/routes/workflow/cases';
import { destroy, edit } from '@/routes/workflow/drafts';
import { create as reapply } from '@/routes/workflow/reapplications';
import type { WorkflowCaseRow } from '@/types';

type Props = {
    row: WorkflowCaseRow;
    commentMaxLength: number;
    onOpen: OpenCaseAction;
};

/**
 * 一時保存の行の操作 =「編集」(設計の「開く」を画面の名前に)「削除」(docs の 設計/ワークフロー/画面/申請と案件.md 4 節)。
 */
function DraftRowActions({ row }: { row: WorkflowCaseRow }) {
    return (
        <>
            {row.can.editDraft && (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={edit(row.id)} aria-label={`${row.name}の編集`}>
                        編集
                    </Link>
                </Button>
            )}
            {row.can.deleteDraft && (
                <ConfirmDeleteDialog
                    triggerLabel={`${row.name}の削除`}
                    title="一時保存の削除"
                    description="この一時保存を削除しますか。この操作は取り消せません。"
                    preserveScroll
                    action={destroy(row.id)}
                    data={{ revision: row.revision }}
                />
            )}
        </>
    );
}

/**
 * 申請の行の操作: 再申請・取止めができる =「再申請」「取止め」「詳細」、引戻しができる =「詳細」「引戻し」、ほか =「詳細」だけ。
 * 取止め・引戻しのボタンは、行の値の写しでページの直下のダイアログを開く(onOpen)。
 */
function CaseRowLinks({ row, commentMaxLength, onOpen }: Props) {
    const targetOf = (operation: CaseOperation): CaseActionTarget => ({
        operation,
        caseId: row.id,
        caseName: row.name,
        revision: row.revision,
        commentMaxLength,
        nodes:
            operation === 'pullBack' ? pullBackNodesOf(row.pullBackNodes) : [],
        fixedPrincipalId: null,
    });

    return (
        <>
            {row.can.reapply && (
                <Button variant="ghost" size="sm" asChild>
                    <Link
                        href={reapply(row.id)}
                        aria-label={`${row.name}の再申請`}
                    >
                        再申請
                    </Link>
                </Button>
            )}
            {row.can.withdraw && (
                <CaseActionButton
                    target={targetOf('withdraw')}
                    placement="row"
                    onOpen={onOpen}
                />
            )}
            <Button variant="ghost" size="sm" asChild>
                <Link href={show(row.id)} aria-label={`${row.name}の詳細`}>
                    詳細
                </Link>
            </Button>
            {row.can.pullBack && (
                <CaseActionButton
                    target={targetOf('pullBack')}
                    placement="row"
                    onOpen={onOpen}
                />
            )}
        </>
    );
}

/**
 * 行の操作の列(一時保存・処理済・参照のタブだけ = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節)。
 */
export function CaseRowActions(props: Props) {
    return (
        <div className="flex items-center justify-end gap-1">
            {props.row.state === 'draft' ? (
                <DraftRowActions row={props.row} />
            ) : (
                <CaseRowLinks {...props} />
            )}
        </div>
    );
}
