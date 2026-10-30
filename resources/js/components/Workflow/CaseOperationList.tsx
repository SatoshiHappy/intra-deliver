import { Pagination } from '@/components/Pagination';
import { CaseListTable } from '@/components/Workflow/CaseListTable';
import { OPERATION_COLUMNS } from '@/lib/Workflow/caseList';
import { CASE_OPERATION_EMPTY_TEXT } from '@/lib/Workflow/caseOperationList';
import { visitCaseOperations } from '@/lib/Workflow/caseOperationVisit';
import type { WorkflowCaseOperationsProps } from '@/types';

/**
 * 申請操作の一覧の面(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.1 節): 申請の表(0 件は文を 1 行)とページ送り。
 * ページ送りは、欄に打った語(typedQ)を添えて部分の再読み込みをする。
 */
export function CaseOperationList({
    query,
    cases,
    typedQ,
}: Pick<WorkflowCaseOperationsProps, 'query' | 'cases'> & { typedQ: string }) {
    return (
        <section
            aria-label="申請の一覧"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <CaseListTable
                columns={OPERATION_COLUMNS}
                rows={cases.data}
                emptyText={CASE_OPERATION_EMPTY_TEXT}
                label="申請の一覧"
                selection={null}
                actions={() => null}
            />
            <Pagination
                list={cases}
                onPage={(page) =>
                    visitCaseOperations({ ...query, q: typedQ }, page)
                }
            />
        </section>
    );
}
