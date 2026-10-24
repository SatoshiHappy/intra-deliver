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
import { actorViewText, targetsCellText } from '@/lib/Workflow/caseDetail';
import type { WorkflowCaseDiagramNode } from '@/types';

/**
 * フロー情報の表(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節。図の文字の代わり): ステップ名(表示名。展開の行は親の下に字下げ)・
 * 状態(処理済みは「処理済み(<種別>)」、保留中は保留中の札)・処理担当者(現在のステップだけ。自分に「(自分)」)・処理実行者(処理済みだけ。
 * 代理で処理したときは「{実行者}({処理者}さんの代理)」)・処理日時(処理済みだけ)。並びはサーバーの写した並び。
 */
export function FlowInfoTable({ nodes }: { nodes: WorkflowCaseDiagramNode[] }) {
    return (
        <Table aria-label="フロー情報">
            <TableHeader>
                <TableRow>
                    <TableHead>ステップ名</TableHead>
                    <TableHead>状態</TableHead>
                    <TableHead>処理担当者</TableHead>
                    <TableHead>処理実行者</TableHead>
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
                            {(node.processed === null
                                ? targetsCellText(node.targets)
                                : null) ?? EMPTY_VALUE}
                        </TableCell>
                        <TableCell className="whitespace-normal">
                            {node.processed === null
                                ? EMPTY_VALUE
                                : actorViewText(node.processed)}
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
