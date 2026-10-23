import { Pagination } from '@/components/Pagination';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import {
    BulkApproveRule,
    CaseBulkBar,
} from '@/components/Workflow/CaseBulkBar';
import { CaseListTable } from '@/components/Workflow/CaseListTable';
import { CaseRowActions } from '@/components/Workflow/CaseRowActions';
import { useCaseSelection } from '@/hooks/Workflow/use-case-selection';
import { bulkActionOf, selectedRowsOf } from '@/lib/Workflow/bulkActions';
import { caseTabOf, emptyTextOf } from '@/lib/Workflow/caseList';
import { caseListUrl, visitCaseList } from '@/lib/Workflow/caseListUrl';
import type { WorkflowCaseIndexProps } from '@/types';

type Props = Pick<
    WorkflowCaseIndexProps,
    'query' | 'cases' | 'commentMaxLength'
> & {
    /** 検索の欄の語(ページ番号の移動の URL だけに使う。選択を戻す鍵と 0 件の文はサーバーが解釈した query.q) */
    typedQ: string;
    onOpenAction: OpenCaseAction;
};

/**
 * 申請一覧の面(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節): 選択の帯(未処理・未確認だけ)・タブごとの列の表・
 * 一括承認の 1 行(未処理だけ)・ページ送り。選択はページの中だけで、タブ・ページ・検索を変えると外す。
 */
export function CaseListPanel({
    query,
    typedQ,
    cases,
    commentMaxLength,
    onOpenAction,
}: Props) {
    const action = bulkActionOf(query.tab);
    const listKey = `${query.tab}|${query.scope}|${cases.currentPage}|${query.q}`;
    const selection = useCaseSelection(listKey, cases.data, action);
    const label = `${caseTabOf(query.tab).label}の申請`;

    return (
        <section
            aria-label={label}
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            {action !== null && (
                <CaseBulkBar
                    action={action}
                    selected={selectedRowsOf(cases.data, selection.selectedIds)}
                    commentMaxLength={commentMaxLength}
                    onDone={selection.clear}
                />
            )}
            <CaseListTable
                tab={query.tab}
                rows={cases.data}
                emptyText={emptyTextOf(query.tab, query.q)}
                label={label}
                selection={action === null ? null : { action, ...selection }}
                actions={(row) => (
                    <CaseRowActions
                        row={row}
                        commentMaxLength={commentMaxLength}
                        onOpen={onOpenAction}
                    />
                )}
            />
            {action === 'approve' && <BulkApproveRule />}
            <Pagination
                list={cases}
                onPage={(page) =>
                    visitCaseList(caseListUrl({ ...query, q: typedQ }, page))
                }
            />
        </section>
    );
}
