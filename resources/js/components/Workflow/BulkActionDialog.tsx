import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, UnshownErrors } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CaseTitle } from '@/components/Workflow/CaseListCells';
import { EMPTY_VALUE } from '@/lib/recordValues';
import {
    BULK_TEXTS,
    bulkApprovePayload,
    bulkConfirmPayload,
} from '@/lib/Workflow/bulkActions';
import type { BulkAction } from '@/lib/Workflow/bulkActions';
import { namesText } from '@/lib/Workflow/caseList';
import { personName } from '@/lib/Workflow/workflowLabels';
import { bulkApprove, bulkConfirm } from '@/routes/workflow/cases';
import type { WorkflowCaseRow } from '@/types';

/** 開いた時の写し(選んだ行 = 一覧の並び。開いている間、ダイアログはこれだけを読む) */
export type BulkTarget = { action: BulkAction; rows: WorkflowCaseRow[] };

const COMMENT_ID = 'bulk-approve-comment';

/**
 * 選んだ申請の表の 1 行(ステップと代理元は行が示した組の写し。代理元が無い = 自分として承認するなら「—」)。
 */
function BulkCaseRow({
    row,
    isApprove,
}: {
    row: WorkflowCaseRow;
    isApprove: boolean;
}) {
    const principal = row.bulkApproval?.principal ?? null;

    return (
        <TableRow>
            <TableCell className="wrap-anywhere whitespace-normal">
                <CaseTitle
                    name={row.name}
                    href={null}
                    number={row.number}
                    flowName={row.flowName}
                />
            </TableCell>
            <TableCell className="wrap-anywhere whitespace-normal">
                {isApprove
                    ? (row.bulkApproval?.nodeName ?? EMPTY_VALUE)
                    : namesText(row.confirmation?.stepNames ?? [])}
            </TableCell>
            {isApprove && (
                <TableCell>
                    {principal === null ? EMPTY_VALUE : personName(principal)}
                </TableCell>
            )}
        </TableRow>
    );
}

/**
 * 選んだ申請の表(一括承認 = 申請名・ステップ・代理元、一括確認 = 申請名・確認するステップ)。並びは一覧の並び = 処理の順。
 */
function BulkCasesTable({ target }: { target: BulkTarget }) {
    const isApprove = target.action === 'approve';
    const label = `選択した申請 ${target.rows.length} 件`;

    return (
        <div className="grid gap-2">
            <p className="text-sm font-medium">{label}</p>
            <Table aria-label={label}>
                <TableHeader>
                    <TableRow>
                        <TableHead>申請名</TableHead>
                        <TableHead>
                            {isApprove ? 'ステップ' : '確認するステップ'}
                        </TableHead>
                        {isApprove && <TableHead>代理元</TableHead>}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {target.rows.map((row) => (
                        <BulkCaseRow
                            key={row.id}
                            row={row}
                            isApprove={isApprove}
                        />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * 一括承認のコメントの欄(任意。誤りはダイアログの上部に出す)。
 */
function BulkCommentField({
    value,
    max,
    isInvalid,
    onChange,
}: {
    value: string;
    max: number;
    isInvalid: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <FormBlock id={COMMENT_ID} label="コメント" help="任意の入力です。">
            <CountedTextInput
                id={COMMENT_ID}
                isMultiline
                value={value}
                max={max}
                invalid={isInvalid}
                hasHelp
                onChange={(event) => onChange(event.target.value)}
            />
        </FormBlock>
    );
}

/**
 * 送る内容と送り先(一括承認 = 行の組の写しとコメント、一括確認 = 案件の id)。成功したら onSucceeded(呼び手が閉じる)。
 */
function useBulkForm(target: BulkTarget, onSucceeded: () => void) {
    const form = useForm({ comment: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            target.action === 'approve'
                ? bulkApprovePayload(target.rows, data.comment)
                : bulkConfirmPayload(target.rows),
        );
        form.submit(
            target.action === 'approve' ? bulkApprove() : bulkConfirm(),
            {
                preserveScroll: true,
                onSuccess: onSucceeded,
            },
        );
    };

    return { form, submit };
}

/**
 * ダイアログの中身(開いた回ごとに作り直す = 呼ぶ側の key)。送った後の移り先は申請一覧(全部が成功ならトースト、ほかは結果のダイアログ)。
 * 選択の誤り(cases・case_ids)とコメントの誤りはダイアログの上部に出す。
 */
function BulkActionForm({
    target,
    commentMaxLength,
    onSucceeded,
}: {
    target: BulkTarget;
    commentMaxLength: number;
    onSucceeded: () => void;
}) {
    const texts = BULK_TEXTS[target.action];
    const { form, submit } = useBulkForm(target, onSucceeded);

    return (
        <form noValidate onSubmit={submit} className="grid gap-4">
            <DialogHeader>
                <DialogTitle>{texts.title(target.rows.length)}</DialogTitle>
                <DialogDescription>{texts.description}</DialogDescription>
            </DialogHeader>
            <UnshownErrors
                errors={form.errors as Record<string, string | undefined>}
                shownKeys={[]}
            />
            <BulkCasesTable target={target} />
            <div className="grid gap-1 rounded-lg border bg-muted/50 p-3 text-sm">
                <p className="font-medium">1 件ずつ処理します</p>
                <p>{texts.notice}</p>
            </div>
            {target.action === 'approve' && (
                <BulkCommentField
                    value={form.data.comment}
                    max={commentMaxLength}
                    isInvalid={form.errors.comment !== undefined}
                    onChange={(value) => form.setData('comment', value)}
                />
            )}
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="outline">
                        キャンセル
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={form.processing}>
                    決定
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * 一括承認・一括確認のダイアログ(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 3 節)。画面は組を組み立てず、一覧の行が示した組を
 * そのまま送る(処理の時の組と違えば、その申請で止まる = 案件の操作/操作.md の 10.7 節)。
 */
export function BulkActionDialog({
    open,
    target,
    openCount,
    commentMaxLength,
    onClose,
    onSucceeded,
}: {
    open: boolean;
    target: BulkTarget | null;
    openCount: number;
    commentMaxLength: number;
    onClose: () => void;
    onSucceeded: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                {target !== null && (
                    <BulkActionForm
                        key={openCount}
                        target={target}
                        commentMaxLength={commentMaxLength}
                        onSucceeded={onSucceeded}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
