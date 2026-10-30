import { Head } from '@inertiajs/react';
import { CaseOperationFilter } from '@/components/Workflow/CaseOperationFilter';
import { CaseOperationList } from '@/components/Workflow/CaseOperationList';
import { useCaseSearch } from '@/hooks/Workflow/use-case-search';
import { visitCaseOperations } from '@/lib/Workflow/caseOperationVisit';
import { index } from '@/routes/workflow/admin/cases';
import type { WorkflowCaseOperationsProps } from '@/types';

/**
 * 申請操作の一覧(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.1 節): 見出し・絞り込みの行・申請の表(20 件ずつ)。
 * 絞り込みとページ送りは部分の再読み込みで URL に残る。行から詳細へ移り、申請操作の面は詳細に出る。
 */
export default function CaseOperations({
    query,
    flows,
    cases,
}: WorkflowCaseOperationsProps) {
    const search = useCaseSearch(query.q, (q) =>
        visitCaseOperations({ ...query, q }, 1, true),
    );

    return (
        <>
            <Head title="申請操作" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold">申請操作</h1>
                    <p className="text-sm text-muted-foreground">
                        すべての申請を参照し、処理担当者の変更や申請の削除などを行います。
                    </p>
                </div>
                <CaseOperationFilter
                    query={query}
                    flows={flows}
                    typedQ={search.value}
                    onKeywordChange={search.change}
                    onKeywordSubmit={search.submit}
                />
                <CaseOperationList
                    query={query}
                    cases={cases}
                    typedQ={search.value}
                />
            </div>
        </>
    );
}

CaseOperations.layout = {
    breadcrumbs: [{ title: '申請操作', href: index() }],
};
