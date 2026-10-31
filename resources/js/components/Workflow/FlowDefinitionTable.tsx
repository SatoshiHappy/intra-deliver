import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    currentVersionLines,
    referrerSummaryText,
} from '@/lib/Workflow/flowDefinitionTexts';
import { show } from '@/routes/workflow/admin/flows';
import type { WorkflowFlowDefinitionRow } from '@/types';

/**
 * 現在の版の欄(「版 3」の下に「2026-10-01 から 終了日なし」。今日の版が無ければ「—」)。
 */
function CurrentVersionCell({ flow }: { flow: WorkflowFlowDefinitionRow }) {
    const lines = currentVersionLines(flow.currentVersion);

    if (lines === null) {
        return <TableCell>—</TableCell>;
    }

    return (
        <TableCell>
            <div className="flex flex-col">
                <span>{lines.name}</span>
                <span className="font-num text-xs text-muted-foreground tabular-nums">
                    {lines.period}
                </span>
            </div>
        </TableCell>
    );
}

/**
 * 表の 1 行(「詳細」は行のフロー名を読み上げの名前に含める)。
 */
function FlowLine({ flow }: { flow: WorkflowFlowDefinitionRow }) {
    return (
        <TableRow>
            <TableCell className="font-medium">{flow.name}</TableCell>
            <TableCell>{flow.moduleName}</TableCell>
            <CurrentVersionCell flow={flow} />
            <TableCell className="font-num tabular-nums">
                {flow.versionCount}
            </TableCell>
            <TableCell className="whitespace-normal">
                {referrerSummaryText(flow.referrerSummary)}
            </TableCell>
            <TableCell>
                <div className="flex items-center justify-end">
                    <Button size="sm" variant="outline" asChild>
                        <Link
                            href={show(flow.code)}
                            aria-label={`${flow.name}の詳細`}
                        >
                            詳細
                        </Link>
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * フロー定義の一覧の表(列 = フロー名・業務モジュール・現在の版・版の数・参照者・操作。並び = フローの並び順)。
 * 操作の列の見出しは読み上げ用だけ。
 */
export function FlowDefinitionTable({
    flows,
}: {
    flows: WorkflowFlowDefinitionRow[];
}) {
    return (
        <Table aria-label="フロー定義の一覧">
            <TableHeader>
                <TableRow>
                    <TableHead>フロー名</TableHead>
                    <TableHead>業務モジュール</TableHead>
                    <TableHead>現在の版</TableHead>
                    <TableHead>版の数</TableHead>
                    <TableHead>参照者</TableHead>
                    <TableHead>
                        <span className="sr-only">操作</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {flows.map((flow) => (
                    <FlowLine key={flow.code} flow={flow} />
                ))}
            </TableBody>
        </Table>
    );
}
