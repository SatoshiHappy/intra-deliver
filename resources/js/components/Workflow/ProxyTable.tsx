import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { StatusBadge } from '@/components/StatusBadge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { ProxyTableRow } from '@/lib/Workflow/proxyList';
import {
    PROXY_KIND_LABELS,
    PROXY_STATUS_BADGES,
    proxyPeriodText,
    proxyTargetText,
} from '@/lib/Workflow/proxyDisplay';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowProxyPerson } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

type Props = {
    /** 表の名前 */
    label: string;
    /** 人の列の見出し(自分の表 = 1 つ、代理の管理 = 代理元・代理先) */
    peopleHeads: string[];
    rows: ProxyTableRow[];
    /** 0 件のときの文(省くと本文は空) */
    emptyText?: string;
    /** 「登録した人」の列を出すか */
    showsRegisteredBy?: boolean;
    /** 削除の送り先(あれば「操作」の列に「削除」を出す) */
    deleteRoute?: (proxyId: number) => RouteDefinition<'delete'>;
};

/** 削除の確かめの題と本文(管理と通知の画面.md の 7.3 節) */
const DELETE_TITLE = '代理の削除';
const DELETE_TEXT =
    'この代理を削除しますか。削除した時から、代理先は代わりに処理できなくなります。';

/**
 * 人の列のセル(名前と所属。名前が無ければコード)。
 */
function PersonCell({ person }: { person: WorkflowProxyPerson }) {
    return (
        <TableCell>
            <div className="flex flex-col">
                <span>{personName(person)}</span>
                {person.department !== null && (
                    <span className="text-xs text-muted-foreground">
                        {person.department}
                    </span>
                )}
            </div>
        </TableCell>
    );
}

/**
 * 代理の表の 1 行(人・種類・対象・期間・状態の札、登録した人、削除)。
 */
function ProxyRowView({
    row,
    showsRegisteredBy,
    deleteRoute,
}: {
    row: ProxyTableRow;
    showsRegisteredBy: boolean;
    deleteRoute: Props['deleteRoute'];
}) {
    return (
        <TableRow>
            {row.people.map((person, position) => (
                <PersonCell key={position} person={person} />
            ))}
            <TableCell>{PROXY_KIND_LABELS[row.kind]}</TableCell>
            <TableCell>{proxyTargetText(row.flowName)}</TableCell>
            <TableCell className="font-num tabular-nums">
                {proxyPeriodText(row.startDate, row.endDate)}
            </TableCell>
            <TableCell>
                <StatusBadge {...PROXY_STATUS_BADGES[row.status]} />
            </TableCell>
            {showsRegisteredBy && (
                <TableCell>
                    {row.registeredBy === null
                        ? ''
                        : personName(row.registeredBy)}
                </TableCell>
            )}
            {deleteRoute !== undefined && (
                <TableCell>
                    <ConfirmDeleteDialog
                        title={DELETE_TITLE}
                        description={DELETE_TEXT}
                        preserveScroll
                        action={deleteRoute(row.id)}
                    />
                </TableCell>
            )}
        </TableRow>
    );
}

/**
 * 代理の表の見出しの行(人の列・種類・対象・期間・状態、登録した人、操作)。
 */
function ProxyTableHead({
    peopleHeads,
    showsRegisteredBy,
    hasOperation,
}: {
    peopleHeads: string[];
    showsRegisteredBy: boolean;
    hasOperation: boolean;
}) {
    return (
        <TableHeader>
            <TableRow>
                {peopleHeads.map((head) => (
                    <TableHead key={head}>{head}</TableHead>
                ))}
                <TableHead>種類</TableHead>
                <TableHead>対象</TableHead>
                <TableHead>期間</TableHead>
                <TableHead>状態</TableHead>
                {showsRegisteredBy && <TableHead>登録した人</TableHead>}
                {hasOperation && <TableHead>操作</TableHead>}
            </TableRow>
        </TableHeader>
    );
}

/**
 * 代理の表(代理の設定の 2 つの面と代理の管理が使う。並びはサーバーで済み)。列 = 人・種類・対象・期間・状態(・登録した人・操作)。
 */
export function ProxyTable({
    label,
    peopleHeads,
    rows,
    emptyText,
    showsRegisteredBy = false,
    deleteRoute,
}: Props) {
    const columns =
        peopleHeads.length +
        4 +
        (showsRegisteredBy ? 1 : 0) +
        (deleteRoute === undefined ? 0 : 1);

    return (
        <Table aria-label={label}>
            <ProxyTableHead
                peopleHeads={peopleHeads}
                showsRegisteredBy={showsRegisteredBy}
                hasOperation={deleteRoute !== undefined}
            />
            <TableBody>
                {rows.length === 0 && emptyText !== undefined && (
                    <TableRow>
                        <TableCell
                            colSpan={columns}
                            className="text-center text-muted-foreground"
                        >
                            {emptyText}
                        </TableCell>
                    </TableRow>
                )}
                {rows.map((row) => (
                    <ProxyRowView
                        key={row.id}
                        row={row}
                        showsRegisteredBy={showsRegisteredBy}
                        deleteRoute={deleteRoute}
                    />
                ))}
            </TableBody>
        </Table>
    );
}
