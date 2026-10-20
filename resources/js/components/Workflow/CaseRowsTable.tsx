import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
    /** 行の操作(案件一覧 = あり。列「現在のステップ」と「操作」を出す。関連する案件 = なし) */
    actions?: (row: WorkflowCaseRow) => ReactNode;
    /** 表の読み上げの名前 */
    label: string;
};

/**
 * 案件名のリンク(一時保存は一時保存の画面 = 申請できる人のときだけ、ほかは詳細。設計/ワークフロー/画面.md 4 節)。
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
 * 案件の行の表(docs の 設計/ワークフロー/画面.md 4 節の案件一覧と 5 節の関連する案件): 案件番号(一時保存は「—」)・案件名(折り返す)・フロー・申請者・
 * 申請日・(現在のステップ = 名前を「、」で並べて折り返す)・状態の札・(操作。見出しは読み上げ用だけ、ボタンはセルの右端)。
 */
export function CaseRowsTable({ rows, emptyText, actions, label }: Props) {
    const columnCount = actions === undefined ? 6 : 8;

    return (
        <Table aria-label={label}>
            <TableHeader>
                <TableRow>
                    <TableHead>申請番号</TableHead>
                    <TableHead>申請名</TableHead>
                    <TableHead>フロー</TableHead>
                    <TableHead>申請者</TableHead>
                    <TableHead>申請日</TableHead>
                    {actions !== undefined && (
                        <TableHead>現在のステップ</TableHead>
                    )}
                    <TableHead>状態</TableHead>
                    {actions !== undefined && (
                        <TableHead>
                            <span className="sr-only">操作</span>
                        </TableHead>
                    )}
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
                        {actions !== undefined && (
                            <TableCell className="min-w-32 wrap-anywhere whitespace-normal">
                                {row.state === 'active' &&
                                row.currentSummary !== null
                                    ? row.currentSummary.name
                                    : EMPTY_VALUE}
                            </TableCell>
                        )}
                        <TableCell>
                            <CaseStateBadge
                                state={row.state}
                                completionReason={row.completionReason}
                                currentNodeState={
                                    row.currentSummary?.state ?? null
                                }
                            />
                        </TableCell>
                        {actions !== undefined && (
                            <TableCell>
                                <div className="flex items-center justify-end gap-1">
                                    {actions(row)}
                                </div>
                            </TableCell>
                        )}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
