import { Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CaseStateBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { EMPTY_VALUE } from '@/lib/recordValues';
import { formatDateOf, personName } from '@/lib/Workflow/workflowLabels';
import { edit as editDraft } from '@/routes/workflow/drafts';
import { show } from '@/routes/workflow/cases';
import type { WorkflowCaseRow } from '@/types';

type Props = {
    rows: WorkflowCaseRow[];
    /** 0 件のときの文 */
    emptyText: string;
    /** 表の読み上げの名前 */
    label: string;
};

/**
 * 案件名のリンク(一時保存は一時保存の画面 = 申請できる人のときだけ、ほかは詳細。設計/ワークフロー/画面/申請と案件.md 4 節)。
 */
function CaseNameCell({ row }: { row: WorkflowCaseRow }) {
    if (row.state === 'draft' && !row.can.editDraft) {
        return <span>{row.name}</span>;
    }

    return (
        <Link
            href={row.state === 'draft' ? editDraft(row.id) : show(row.id)}
            className="text-primary underline-offset-4 hover:underline"
        >
            {row.name}
        </Link>
    );
}

/**
 * 関連する案件の表(docs の 設計/ワークフロー/画面/申請と案件.md 5 節): 申請番号・申請名(折り返す)・フロー・申請者・申請日・状態の札。
 * 申請一覧の表は列がタブごとに違うので別の部品(CaseListTable)。
 */
export function CaseRowsTable({ rows, emptyText, label }: Props) {
    const columnCount = 6;

    return (
        <Table aria-label={label}>
            <TableHeader>
                <TableRow>
                    <TableHead>申請番号</TableHead>
                    <TableHead>申請名</TableHead>
                    <TableHead>フロー</TableHead>
                    <TableHead>申請者</TableHead>
                    <TableHead>申請日</TableHead>
                    <TableHead>状態</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.length === 0 && (
                    <TableRow>
                        <TableCell
                            colSpan={columnCount}
                            className="text-center text-muted-foreground"
                        >
                            {emptyText}
                        </TableCell>
                    </TableRow>
                )}
                {rows.map((row) => (
                    <TableRow key={row.id}>
                        <TableCell className="font-num tabular-nums">
                            {row.number ?? EMPTY_VALUE}
                        </TableCell>
                        <TableCell className="min-w-48 wrap-anywhere whitespace-normal">
                            <CaseNameCell row={row} />
                        </TableCell>
                        <TableCell>{row.flowName}</TableCell>
                        <TableCell>{personName(row.applicant)}</TableCell>
                        <TableCell className="font-num tabular-nums">
                            {row.appliedAt === null
                                ? EMPTY_VALUE
                                : formatDateOf(row.appliedAt)}
                        </TableCell>
                        <TableCell>
                            <CaseStateBadge
                                state={row.state}
                                completionReason={row.completionReason}
                                currentNodeState={
                                    row.currentSummary?.state ?? null
                                }
                            />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
