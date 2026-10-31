import { HeadingHelp } from '@/components/HeadingHelp';
import { ProxyTable } from '@/components/Workflow/ProxyTable';
import type { ProxyTableRow } from '@/lib/Workflow/proxyList';
import type { RouteDefinition } from '@/wayfinder';

/**
 * 代理の設定の面(見出し(横の「?」に説明)・「全 N 件」と表。管理と通知の画面.md の 7.3 節の「自分の代理先」「自分の代理元」)。
 */
export function ProxySurface({
    title,
    note,
    peopleHead,
    emptyText,
    rows,
    deleteRoute,
}: {
    title: string;
    note: string;
    peopleHead: string;
    emptyText: string;
    rows: ProxyTableRow[];
    deleteRoute?: (proxyId: number) => RouteDefinition<'delete'>;
}) {
    return (
        <section
            aria-label={title}
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex items-center gap-3">
                <div className="flex items-center gap-1.5">
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <HeadingHelp label={title}>{note}</HeadingHelp>
                </div>
                <span className="text-sm text-muted-foreground">
                    全{' '}
                    <span className="font-num tabular-nums">{rows.length}</span>{' '}
                    件
                </span>
            </div>
            <ProxyTable
                label={title}
                peopleHeads={[peopleHead]}
                rows={rows}
                emptyText={emptyText}
                deleteRoute={deleteRoute}
            />
        </section>
    );
}
