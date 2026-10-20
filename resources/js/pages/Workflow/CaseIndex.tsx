import { Head, Link, router } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { Button } from '@/components/ui/button';
import {
    CaseActionButton,
    CaseActionDialog,
    pullBackNodesOf,
    useCaseActionDialog,
} from '@/components/Workflow/CaseActionDialog';
import type {
    CaseActionTarget,
    CaseOperation,
    OpenCaseAction,
} from '@/components/Workflow/CaseActionDialog';
import { CasePagination } from '@/components/Workflow/CasePagination';
import { CaseRowsTable } from '@/components/Workflow/CaseRowsTable';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/workflow/cases';
import { destroy, edit } from '@/routes/workflow/drafts';
import { create as reapply } from '@/routes/workflow/reapplications';
import type {
    WorkflowCaseIndexProps,
    WorkflowCaseRow,
    WorkflowCaseScope,
    WorkflowCaseTab,
} from '@/types';

type Props = WorkflowCaseIndexProps;

/** タブ・切替・ページを変えたときに取り直す props(設計/ワークフロー/画面.md 4 節) */
const LIST_PROPS = ['query', 'counts', 'cases'];

/** タブ(並びの順)と、0 件のときの文 */
const TABS: { tab: WorkflowCaseTab; label: string; empty: string }[] = [
    { tab: 'draft', label: '一時保存', empty: '一時保存した申請はありません' },
    {
        tab: 'pending',
        label: '未処理',
        empty: '処理を待っている申請はありません',
    },
    { tab: 'processed', label: '処理済', empty: '処理した申請はありません' },
    { tab: 'referenced', label: '参照', empty: '参照できる申請はありません' },
];

/** 未完了・完了の切替(処理済・参照のときだけ) */
const SCOPES: { scope: WorkflowCaseScope; label: string }[] = [
    { scope: 'active', label: '未完了' },
    { scope: 'completed', label: '完了' },
];

/** 切替を持つタブ */
const SCOPED_TABS: WorkflowCaseTab[] = ['processed', 'referenced'];

/**
 * クエリ(タブ・切替・ページ)の URL(既定の値は付けない。タブの既定 = 未処理、切替の既定 = 未完了、ページの既定 = 1)。
 */
function listUrl(tab: WorkflowCaseTab, scope: WorkflowCaseScope, page = 1) {
    return index.url({
        query: {
            tab: tab === 'pending' ? undefined : tab,
            scope:
                SCOPED_TABS.includes(tab) && scope !== 'active'
                    ? scope
                    : undefined,
            page: page === 1 ? undefined : page,
        },
    });
}

/**
 * 一覧を、クエリを変えた URL へ部分再読み込みする(URL に残すので、再読み込み・戻る操作で同じ一覧になる)。
 */
function visit(url: string) {
    router.get(url, {}, { only: LIST_PROPS, preserveState: true });
}

/** タブ・切替のリンクの見た目(今の値 = 現在地の見た目 bg-secondary) */
function navLinkClass(isCurrent: boolean) {
    return cn(
        'inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground',
        isCurrent && 'bg-secondary text-secondary-foreground',
    );
}

/**
 * 行の操作(行の can。設計/ワークフロー/画面.md 4 節): 一時保存 =「編集」(設計の「開く」を画面の意匠 11 節・方針 9(a) で画面の名前に)「削除」、引戻しができる =「詳細」「引戻し」、再申請・
 * 取止めができる =「再申請」「取止め」「詳細」、
 * ほか =「詳細」だけ。取止め・引戻しのボタンは、行の値の写しでページの直下のダイアログを開く(onOpen)。
 */
function RowActions({
    row,
    commentMaxLength,
    onOpen,
}: {
    row: WorkflowCaseRow;
    commentMaxLength: number;
    onOpen: OpenCaseAction;
}) {
    const targetOf = (operation: CaseOperation): CaseActionTarget => ({
        operation,
        caseId: row.id,
        caseName: row.name,
        revision: row.revision,
        commentMaxLength,
        nodes:
            operation === 'pullBack' ? pullBackNodesOf(row.pullBackNodes) : [],
    });

    if (row.state === 'draft') {
        return (
            <>
                {row.can.editDraft && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link
                            href={edit(row.id)}
                            aria-label={`${row.name}の編集`}
                        >
                            編集
                        </Link>
                    </Button>
                )}
                {row.can.deleteDraft && (
                    <ConfirmDeleteDialog
                        triggerLabel={`${row.name}の削除`}
                        title="一時保存の削除"
                        description="この一時保存を削除しますか。この操作は取り消せません。"
                        preserveScroll
                        action={destroy(row.id)}
                        data={{ revision: row.revision }}
                    />
                )}
            </>
        );
    }

    return (
        <>
            {row.can.reapply && (
                <Button variant="ghost" size="sm" asChild>
                    <Link
                        href={reapply(row.id)}
                        aria-label={`${row.name}の再申請`}
                    >
                        再申請
                    </Link>
                </Button>
            )}
            {row.can.withdraw && (
                <CaseActionButton
                    target={targetOf('withdraw')}
                    placement="row"
                    onOpen={onOpen}
                />
            )}
            <Button variant="ghost" size="sm" asChild>
                <Link href={show(row.id)} aria-label={`${row.name}の詳細`}>
                    詳細
                </Link>
            </Button>
            {row.can.pullBack && (
                <CaseActionButton
                    target={targetOf('pullBack')}
                    placement="row"
                    onOpen={onOpen}
                />
            )}
        </>
    );
}

/**
 * 案件一覧(docs の 設計/ワークフロー/画面.md 4 節): タブ「一時保存 N」「未処理 N」「処理済」「参照」(リンクの並び。今のタブに aria-current)と、
 * 処理済・参照のときだけ「未完了」「完了」の切替、案件の表とページング。タブ・切替・ページは部分再読み込み。
 */
export default function CaseIndex({
    query,
    counts,
    cases,
    commentMaxLength,
}: Props) {
    const { openAction, dialogProps } = useCaseActionDialog();
    const current = TABS.find((tab) => tab.tab === query.tab) ?? TABS[1];
    const countOf = (tab: WorkflowCaseTab) =>
        tab === 'draft'
            ? counts.draft
            : tab === 'pending'
              ? counts.pending
              : null;

    return (
        <>
            <Head title="申請一覧" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold">申請一覧</h1>
                    <p className="text-sm text-muted-foreground">
                        自分の申請と、処理を求められている申請です。
                    </p>
                </div>
                <div className="flex flex-col gap-3">
                    <nav
                        aria-label="申請のタブ"
                        className="flex flex-wrap items-center gap-1"
                    >
                        {TABS.map(({ tab, label }) => {
                            const count = countOf(tab);
                            const isCurrent = tab === query.tab;

                            return (
                                <Link
                                    key={tab}
                                    href={listUrl(tab, 'active')}
                                    only={LIST_PROPS}
                                    preserveState
                                    aria-current={
                                        isCurrent ? 'page' : undefined
                                    }
                                    className={navLinkClass(isCurrent)}
                                >
                                    {label}
                                    {count !== null && (
                                        <span className="font-num tabular-nums">
                                            {count}
                                        </span>
                                    )}
                                </Link>
                            );
                        })}
                    </nav>
                    {SCOPED_TABS.includes(query.tab) && (
                        <nav
                            aria-label="未完了と完了の切替"
                            className="flex flex-wrap items-center gap-1"
                        >
                            {SCOPES.map(({ scope, label }) => {
                                const isCurrent = scope === query.scope;

                                return (
                                    <Link
                                        key={scope}
                                        href={listUrl(query.tab, scope)}
                                        only={LIST_PROPS}
                                        preserveState
                                        aria-current={
                                            isCurrent ? 'page' : undefined
                                        }
                                        className={navLinkClass(isCurrent)}
                                    >
                                        {label}
                                    </Link>
                                );
                            })}
                        </nav>
                    )}
                </div>
                <section
                    aria-label={`${current.label}の申請`}
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <CaseRowsTable
                        rows={cases.data}
                        emptyText={current.empty}
                        label={`${current.label}の申請`}
                        actions={(row) => (
                            <RowActions
                                row={row}
                                commentMaxLength={commentMaxLength}
                                onOpen={openAction}
                            />
                        )}
                    />
                    <CasePagination
                        currentPage={cases.currentPage}
                        lastPage={cases.lastPage}
                        total={cases.total}
                        onPage={(page) =>
                            visit(listUrl(query.tab, query.scope, page))
                        }
                    />
                </section>
            </div>
            {/* 行の処理のダイアログは表の外に 1 つ(誤りの後に行が一覧から外れても閉じない = CaseActionDialog) */}
            <CaseActionDialog {...dialogProps} />
        </>
    );
}

CaseIndex.layout = {
    breadcrumbs: [{ title: '申請一覧', href: index() }],
};
