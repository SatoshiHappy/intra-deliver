import type { ReactNode } from 'react';
import { FieldHelp } from '@/components/FormBlock';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    CaseNameCell,
    CurrentStepCell,
    DueCell,
    ElapsedCell,
    PersonNames,
} from '@/components/Workflow/CaseListCells';
import { CaseStateBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import { BULK_TEXTS, allCheckedState } from '@/lib/Workflow/bulkActions';
import type { BulkAction } from '@/lib/Workflow/bulkActions';
import { namesText } from '@/lib/Workflow/caseList';
import type { CaseListColumn } from '@/lib/Workflow/caseList';
import { formatDateOf, personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowCaseRow } from '@/types';

/** 選択の欄の状態(選択の欄を持つタブだけ。use-case-selection の値と、そのタブの一括の操作) */
type CaseListSelection = {
    action: BulkAction;
    selectable: number[];
    selectedIds: number[];
    toggle: (id: number, isChecked: boolean) => void;
    toggleAll: () => void;
};

type Props = {
    /** 表の列(左から。申請一覧はタブごと、申請操作の一覧は固定) */
    columns: CaseListColumn[];
    rows: WorkflowCaseRow[];
    emptyText: string;
    /** 表の読み上げの名前 */
    label: string;
    selection: CaseListSelection | null;
    actions: (row: WorkflowCaseRow) => ReactNode;
};

/** 列の見出し(選択・経過日数・操作は見出しの部品で描く) */
const HEADS: Record<CaseListColumn, string> = {
    select: 'すべて選択',
    name: '申請名',
    applicant: '申請者',
    appliedAt: '申請日',
    currentStep: '現在のステップ',
    principals: '代理元',
    elapsed: '経過日数',
    due: '期限',
    confirmSteps: '確認するステップ',
    confirmOpenedAt: '確認が開いた日時',
    confirmedSteps: '確認したステップ',
    confirmedAt: '確認日時',
    state: '状態',
    actions: '操作',
};

/** 経過日数の「?」の説明(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節) */
const ELAPSED_HELP = '今のステップに届いてからの日数です。';

/**
 * 日時のセル(無ければ「—」)。
 */
function datetimeText(at: string | null | undefined): string {
    return at === null || at === undefined ? EMPTY_VALUE : formatDatetime(at);
}

/**
 * 列の見出しのセル。
 */
function HeadCell({
    column,
    selection,
}: {
    column: CaseListColumn;
    selection: CaseListSelection | null;
}) {
    if (column === 'select' && selection !== null) {
        return (
            <TableHead className="w-10">
                <Checkbox
                    aria-label={HEADS.select}
                    checked={allCheckedState(
                        selection.selectedIds,
                        selection.selectable,
                    )}
                    disabled={selection.selectable.length === 0}
                    onCheckedChange={() => selection.toggleAll()}
                />
            </TableHead>
        );
    }

    return (
        <TableHead>
            {column === 'actions' ? (
                <span className="sr-only">{HEADS.actions}</span>
            ) : (
                <span className="inline-flex items-center gap-1">
                    {HEADS[column]}
                    {column === 'elapsed' && (
                        <FieldHelp id="case-list-elapsed" label={HEADS.elapsed}>
                            {ELAPSED_HELP}
                        </FieldHelp>
                    )}
                </span>
            )}
        </TableHead>
    );
}

/**
 * 行の選択の欄(選べない行は押せず、読み上げに理由を添える)。
 */
function SelectCell({
    row,
    selection,
}: {
    row: WorkflowCaseRow;
    selection: CaseListSelection;
}) {
    const isSelectable = selection.selectable.includes(row.id);
    const reasonId = `case-select-${row.id}-reason`;
    const reason = isSelectable
        ? null
        : BULK_TEXTS[selection.action].notSelectable;

    return (
        <>
            <Checkbox
                aria-label={row.name}
                aria-describedby={reason === null ? undefined : reasonId}
                checked={selection.selectedIds.includes(row.id)}
                disabled={!isSelectable}
                onCheckedChange={(checked) =>
                    selection.toggle(row.id, checked === true)
                }
            />
            {reason !== null && (
                <span id={reasonId} className="sr-only">
                    {reason}
                </span>
            )}
        </>
    );
}

/**
 * 行の 1 つのセルの中身(選択の欄と操作の列は呼び手が描く)。
 */
function cellOf(column: CaseListColumn, row: WorkflowCaseRow): ReactNode {
    switch (column) {
        case 'name':
            return <CaseNameCell row={row} />;
        case 'applicant':
            return <PersonNames names={[personName(row.applicant)]} />;
        case 'appliedAt':
            return row.appliedAt === null
                ? EMPTY_VALUE
                : formatDateOf(row.appliedAt);
        case 'currentStep':
            return <CurrentStepCell row={row} />;
        case 'principals':
            return <PersonNames names={row.principals.map(personName)} />;
        case 'elapsed':
            return <ElapsedCell deadline={row.deadline} />;
        case 'due':
            return <DueCell deadline={row.deadline} />;
        case 'confirmSteps':
        case 'confirmedSteps':
            return namesText(row.confirmation?.stepNames ?? []);
        case 'confirmOpenedAt':
        case 'confirmedAt':
            return datetimeText(row.confirmation?.at);
        case 'state':
            return (
                <CaseStateBadge
                    state={row.state}
                    completionReason={row.completionReason}
                    currentNodeState={row.currentSummary?.state ?? null}
                />
            );
        default:
            return null;
    }
}

/** 列ごとのセルの書式(申請名・現在のステップ・名前の並びは折り返す、日付・日数は数字の書体) */
const CELL_CLASSES: Partial<Record<CaseListColumn, string>> = {
    name: 'min-w-48 wrap-anywhere whitespace-normal',
    currentStep: 'min-w-32 wrap-anywhere whitespace-normal',
    principals: 'wrap-anywhere whitespace-normal',
    confirmSteps: 'wrap-anywhere whitespace-normal',
    confirmedSteps: 'wrap-anywhere whitespace-normal',
    appliedAt: 'font-num tabular-nums',
    confirmOpenedAt: 'font-num tabular-nums',
    confirmedAt: 'font-num tabular-nums',
};

/**
 * 行のセル(選択の欄・操作の列・ほかの列)。
 */
function RowCells({
    columns,
    row,
    selection,
    actions,
}: Pick<Props, 'selection' | 'actions'> & {
    columns: CaseListColumn[];
    row: WorkflowCaseRow;
}) {
    return columns.map((column) => (
        <TableCell key={column} className={CELL_CLASSES[column]}>
            {column === 'select' && selection !== null && (
                <SelectCell row={row} selection={selection} />
            )}
            {column === 'actions' && actions(row)}
            {cellOf(column, row)}
        </TableCell>
    ));
}

/**
 * 申請一覧の表(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節、列 = 認可と一覧.md の 2.2 節): 呼び手が渡す列と、
 * 未処理・未確認のタブの左端の選択の欄。0 件は文を 1 行。
 */
export function CaseListTable({
    columns,
    rows,
    emptyText,
    label,
    selection,
    actions,
}: Props) {
    return (
        <Table aria-label={label}>
            <TableHeader>
                <TableRow>
                    {columns.map((column) => (
                        <HeadCell
                            key={column}
                            column={column}
                            selection={selection}
                        />
                    ))}
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.length === 0 && (
                    <TableRow>
                        <TableCell
                            colSpan={columns.length}
                            className="text-center text-muted-foreground"
                        >
                            {emptyText}
                        </TableCell>
                    </TableRow>
                )}
                {rows.map((row) => (
                    <TableRow key={row.id}>
                        <RowCells
                            columns={columns}
                            row={row}
                            selection={selection}
                            actions={actions}
                        />
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
