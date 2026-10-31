import { Head, usePage } from '@inertiajs/react';
import { ProxyAddDialog } from '@/components/Workflow/ProxyAddDialog';
import { ProxySurface } from '@/components/Workflow/ProxySurface';
import { PersonLinksContext } from '@/hooks/Workflow/use-person-links';
import { ownTableRow } from '@/lib/Workflow/proxyList';
import { destroy, index } from '@/routes/workflow/proxies';
import type { WorkflowProxiesProps } from '@/types';

/**
 * 代理の設定(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節): 見出しと主ボタン「代理を追加」、
 * 面「自分の代理先」(削除できる)と「自分の代理元」(この画面では変更できない)。並びと状態はサーバーで済み。
 * ゲスト(共有プロパティ account の利用者が無い)は追加できない(押しても 403 になる)ので、主ボタンを出さない。
 */
export default function Proxies({
    outgoing,
    incoming,
    flows,
    today,
    links,
}: WorkflowProxiesProps) {
    const { account } = usePage().props;

    return (
        <PersonLinksContext value={links}>
            <Head title="代理の設定" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold">代理の設定</h1>
                        <p className="text-sm text-muted-foreground">
                            自分の承認や申請を、ほかの利用者が代わりに処理できるように設定します。
                        </p>
                    </div>
                    {account.user !== null && (
                        <ProxyAddDialog
                            isAdmin={false}
                            flows={flows}
                            today={today}
                        />
                    )}
                </div>
                <ProxySurface
                    title="自分の代理先"
                    note="自分の代わりに処理できる人です。"
                    peopleHead="代理先"
                    emptyText="代理先はありません"
                    rows={outgoing.map(ownTableRow)}
                    deleteRoute={destroy}
                />
                <ProxySurface
                    title="自分の代理元"
                    note="自分が代わりに処理できる相手です。この画面では変更できません。"
                    peopleHead="代理元"
                    emptyText="代理元はありません"
                    rows={incoming.map(ownTableRow)}
                />
            </div>
        </PersonLinksContext>
    );
}

Proxies.layout = {
    breadcrumbs: [{ title: '代理の設定', href: index() }],
};
