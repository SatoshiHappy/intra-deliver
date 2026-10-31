import { Head } from '@inertiajs/react';
import { Pagination } from '@/components/Pagination';
import { ProxyAddDialog } from '@/components/Workflow/ProxyAddDialog';
import { ProxyAdminFilter } from '@/components/Workflow/ProxyAdminFilter';
import { ProxyTable } from '@/components/Workflow/ProxyTable';
import { PersonLinksContext } from '@/hooks/Workflow/use-person-links';
import { adminEmptyTextOf, adminTableRow } from '@/lib/Workflow/proxyList';
import { visitProxyList } from '@/lib/Workflow/proxyListVisit';
import { destroy, index } from '@/routes/workflow/admin/proxies';
import type { WorkflowAdminProxiesProps } from '@/types';

/**
 * 代理の管理(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.4 節): 見出しと主ボタン「代理を追加」(代理元を選ぶ)、
 * 絞り込み(代理元・代理先・種類・有効な代理だけ)、すべての利用者の代理の表(20 件ずつ)。絞り込みとページ送りは部分の再読み込み。
 */
export default function Proxies({
    query,
    flows,
    today,
    links,
    proxies,
}: WorkflowAdminProxiesProps) {
    return (
        <PersonLinksContext value={links}>
            <Head title="代理の管理" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold">代理の管理</h1>
                        <p className="text-sm text-muted-foreground">
                            すべての利用者の代理を参照し、追加と削除を行います。
                        </p>
                    </div>
                    <ProxyAddDialog isAdmin flows={flows} today={today} />
                </div>
                <ProxyAdminFilter query={query} />
                <section
                    aria-label="代理の一覧"
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <ProxyTable
                        label="代理"
                        peopleHeads={['代理元', '代理先']}
                        rows={proxies.data.map(adminTableRow)}
                        emptyText={adminEmptyTextOf(query)}
                        showsRegisteredBy
                        deleteRoute={destroy}
                    />
                    <Pagination
                        list={proxies}
                        onPage={(page) => visitProxyList(query, page)}
                    />
                </section>
            </div>
        </PersonLinksContext>
    );
}

Proxies.layout = {
    breadcrumbs: [{ title: '代理の管理', href: index() }],
};
