import { Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/StatusBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { VersionToggleDialog } from '@/components/Workflow/VersionToggleDialog';
import {
    NO_END_DATE,
    versionLabel,
    versionStateLabel,
} from '@/lib/Workflow/flowDefinitionTexts';
import { flowShowUrl, propsOfTab } from '@/lib/Workflow/flowDefinitionUrl';
import { formatDate } from '@/lib/recordValues';
import type { WorkflowFlowVersionRow } from '@/types';

/**
 * 版の行の操作(枠つきのボタン。「図を見る」= フロー図のタブでその版、「無効にする」/「有効にする」= 確かめのダイアログの後に送る)。
 */
function VersionActions({
    flowCode,
    version,
}: {
    flowCode: string;
    version: WorkflowFlowVersionRow;
}) {
    return (
        <div className="flex items-center justify-end gap-1">
            <Button variant="outline" size="sm" asChild>
                <Link
                    href={flowShowUrl(flowCode, {
                        version: version.number,
                        tab: 'diagram',
                    })}
                    only={propsOfTab('diagram')}
                    preserveState
                    preserveScroll
                    aria-label={`${versionLabel(version.number)} の図を見る`}
                >
                    図を見る
                </Link>
            </Button>
            <VersionToggleDialog
                flowCode={flowCode}
                number={version.number}
                isDisabled={version.isDisabled}
            />
        </div>
    );
}

/**
 * 版の表の 1 行(列 = 版・開始日・終了日・状態・操作)。状態の札は、有効 = 緑み(代理の管理の「有効」と同じ)、無効 = 灰色。日付は YYYY/MM/DD。終了日は次の版の開始日の前日(最後の版は「終了日なし」)。
 */
function VersionLine({
    flowCode,
    version,
}: {
    flowCode: string;
    version: WorkflowFlowVersionRow;
}) {
    return (
        <TableRow>
            <TableCell className="font-medium">
                {versionLabel(version.number)}
                {version.isCurrent && (
                    <Badge variant="secondary" className="ml-2">
                        現在
                    </Badge>
                )}
            </TableCell>
            <TableCell className="font-num tabular-nums">
                {formatDate(version.startDate)}
            </TableCell>
            <TableCell className="font-num tabular-nums">
                {version.displayEndDate === null ? (
                    <span className="text-muted-foreground">{NO_END_DATE}</span>
                ) : (
                    formatDate(version.displayEndDate)
                )}
            </TableCell>
            <TableCell>
                <StatusBadge
                    label={versionStateLabel(version.isDisabled)}
                    tone={version.isDisabled ? 'settled' : 'pending'}
                />
            </TableCell>
            <TableCell>
                <VersionActions flowCode={flowCode} version={version} />
            </TableCell>
        </TableRow>
    );
}

/**
 * 版の表(並び = 番号の降順。新しい版が上)。
 */
export function FlowVersionTable({
    flowCode,
    versions,
}: {
    flowCode: string;
    versions: WorkflowFlowVersionRow[];
}) {
    return (
        <Table aria-label="版の一覧">
            <TableHeader>
                <TableRow>
                    <TableHead>版</TableHead>
                    <TableHead>開始日</TableHead>
                    <TableHead>終了日</TableHead>
                    <TableHead>状態</TableHead>
                    <TableHead>
                        <span className="sr-only">操作</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {versions.map((version) => (
                    <VersionLine
                        key={version.number}
                        flowCode={flowCode}
                        version={version}
                    />
                ))}
            </TableBody>
        </Table>
    );
}
