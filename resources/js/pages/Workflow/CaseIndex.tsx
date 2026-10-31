import { Head } from '@inertiajs/react';
import { BulkResultDialog } from '@/components/Workflow/BulkResultDialog';
import {
    CaseActionDialog,
    useCaseActionDialog,
} from '@/components/Workflow/CaseActionDialog';
import { CaseListPanel } from '@/components/Workflow/CaseListPanel';
import { CaseListTabs } from '@/components/Workflow/CaseListTabs';
import { CaseSearchField } from '@/components/Workflow/CaseSearchField';
import { useBulkResult } from '@/hooks/Workflow/use-bulk-result';
import { useCaseSearch } from '@/hooks/Workflow/use-case-search';
import { PersonLinksContext } from '@/hooks/Workflow/use-person-links';
import { indexAttachOf } from '@/lib/Workflow/caseActionAttach';
import { caseListUrl, visitCaseList } from '@/lib/Workflow/caseListUrl';
import { index } from '@/routes/workflow/cases';
import type { WorkflowCaseIndexProps } from '@/types';

/**
 * 申請一覧(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節・3 節): タブと切替・検索の欄・一覧の面。タブ・切替・ページ・検索は
 * 部分の再読み込みで、どの移動の URL にも欄の語を入れる(打ってすぐ押した移動でも打った語を失わないため)。行の処理のダイアログと一括の結果のダイアログはページの直下に 1 つずつ(誤りの後に行が一覧から外れても閉じない)。
 */
export default function CaseIndex(props: WorkflowCaseIndexProps) {
    const { query, counts, cases, commentMaxLength } = props;
    const { openAction, dialogProps } = useCaseActionDialog();
    const bulkResult = useBulkResult();
    const search = useCaseSearch(query.q, (q) =>
        visitCaseList(caseListUrl({ ...query, q }), true),
    );

    return (
        <PersonLinksContext value={props.links}>
            <Head title="申請一覧" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold">申請一覧</h1>
                    <p className="text-sm text-muted-foreground">
                        自分の申請と、処理を求められている申請です。
                    </p>
                </div>
                <CaseListTabs
                    query={{ ...query, q: search.value }}
                    counts={counts}
                />
                <CaseSearchField
                    value={search.value}
                    onChange={search.change}
                    onSubmit={search.submit}
                />
                <CaseListPanel
                    query={query}
                    typedQ={search.value}
                    cases={cases}
                    commentMaxLength={commentMaxLength}
                    onOpenAction={openAction}
                />
            </div>
            <CaseActionDialog {...dialogProps} attach={indexAttachOf(props)} />
            <BulkResultDialog
                result={bulkResult.result}
                isOpen={bulkResult.isOpen}
                onClose={bulkResult.close}
            />
        </PersonLinksContext>
    );
}

CaseIndex.layout = {
    breadcrumbs: [{ title: '申請一覧', href: index() }],
};
