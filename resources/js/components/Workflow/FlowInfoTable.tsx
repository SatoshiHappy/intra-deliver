import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CaseStepBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowCaseDiagramNode } from '@/types';

/** 処理対象者(現在のステップだけ。自分には「(自分)」と「処理対象者」の札) */
function Targets({ node }: { node: WorkflowCaseDiagramNode }) {
    if (node.processed !== null || node.people.length === 0) {
        return EMPTY_VALUE;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <span>
                {node.people
                    .map(
                        (person) =>
                            `${personName(person)}${person.isMe ? '(自分)' : ''}`,
                    )
                    .join('、')}
            </span>
            <Badge variant="outline">処理対象者</Badge>
        </div>
    );
}

/**
 * フロー情報の表(docs の 設計/ワークフロー/画面.md 5 節、ルートの深さ/画面.md 7 節。図の文字の代わり): ステップ名(表示名。展開の行は親の下に字下げ)・状態(処理済みは「処理済み(<種別>)」)・
 * 処理対象者(現在のステップだけ)・処理者・処理日時(処理済みだけ)。並びはサーバーの写した並び。
 */
export function FlowInfoTable({ nodes }: { nodes: WorkflowCaseDiagramNode[] }) {
    return (
        <Table aria-label="フロー情報">
            <TableHeader>
                <TableRow>
                    <TableHead>ステップ名</TableHead>
                    <TableHead>状態</TableHead>
                    <TableHead>処理対象者</TableHead>
                    <TableHead>処理者</TableHead>
                    <TableHead>処理日時</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {nodes.map((node) => (
                    <TableRow key={node.id}>
                        <TableCell
                            className={cn(node.parentId !== null && 'pl-8')}
                        >
                            {node.name}
                        </TableCell>
                        <TableCell>
                            <CaseStepBadge
                                stepState={node.stepState}
                                action={node.processed?.action ?? null}
                            />
                        </TableCell>
                        <TableCell className="whitespace-normal">
                            <Targets node={node} />
                        </TableCell>
                        <TableCell>
                            {node.processed === null || node.people.length === 0
                                ? EMPTY_VALUE
                                : personName(node.people[0])}
                        </TableCell>
                        <TableCell className="font-num tabular-nums">
                            {node.processed === null ||
                            node.processed.at === null
                                ? EMPTY_VALUE
                                : formatDatetime(node.processed.at)}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
