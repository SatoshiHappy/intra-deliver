import { Button } from '@/components/ui/button';
import {
    Dialog,
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
import { BulkOutcomeBadge } from '@/components/Workflow/WorkflowStatusBadge';
import {
    BULK_TEXTS,
    UNAVAILABLE_CASE,
    guidanceText,
    resultSummaryOf,
} from '@/lib/Workflow/bulkActions';
import { show } from '@/routes/workflow/cases';
import type { WorkflowBulkResult } from '@/types';

/**
 * 結果の表「処理の結果」(申請名(閲覧できない申請はリンクなしの文)・結果の札・対応)。
 */
function ResultTable({ result }: { result: WorkflowBulkResult }) {
    return (
        <div className="grid gap-2">
            <p className="text-sm font-medium">処理の結果</p>
            <Table aria-label="処理の結果">
                <TableHeader>
                    <TableRow>
                        <TableHead>申請名</TableHead>
                        <TableHead>結果</TableHead>
                        <TableHead>対応</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {result.rows.map((row) => (
                        <TableRow key={row.caseId}>
                            <TableCell className="wrap-anywhere whitespace-normal">
                                {row.name === null ? (
                                    UNAVAILABLE_CASE
                                ) : (
                                    <CaseTitle
                                        name={row.name}
                                        href={show.url(row.caseId)}
                                        number={row.number}
                                        flowName={row.flowName}
                                    />
                                )}
                            </TableCell>
                            <TableCell>
                                <BulkOutcomeBadge outcome={row.result} />
                            </TableCell>
                            <TableCell className="wrap-anywhere whitespace-normal">
                                {guidanceText(row.guidance)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * 一括の結果のダイアログ(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 3 節: 全部が成功しなかったとき、一覧を描き直した後に開く)。
 * 「申請一覧を再表示」は閉じるだけ(一覧はリダイレクトで描き直し済み)。
 */
export function BulkResultDialog({
    result,
    isOpen,
    onClose,
}: {
    result: WorkflowBulkResult | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                {result !== null && (
                    <div className="grid gap-4">
                        <DialogHeader>
                            <DialogTitle>
                                {BULK_TEXTS[result.action].resultTitle}
                            </DialogTitle>
                            <DialogDescription>
                                {resultSummaryOf(result)}
                            </DialogDescription>
                        </DialogHeader>
                        <ResultTable result={result} />
                        <DialogFooter>
                            <Button type="button" onClick={onClose}>
                                申請一覧を再表示
                            </Button>
                        </DialogFooter>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
