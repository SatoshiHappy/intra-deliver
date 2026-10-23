import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { CASE_TABS, SCOPED_TABS, countOf } from '@/lib/Workflow/caseList';
import { CASE_LIST_PROPS, caseListUrl } from '@/lib/Workflow/caseListUrl';
import type { CaseListQuery } from '@/lib/Workflow/caseListUrl';
import type { WorkflowCaseIndexProps, WorkflowCaseScope } from '@/types';

/** 未完了・完了の切替(処理済・確認済・参照のときだけ) */
const SCOPES: { scope: WorkflowCaseScope; label: string }[] = [
    { scope: 'active', label: '未完了' },
    { scope: 'completed', label: '完了' },
];

/** タブ・切替のリンクの見た目(今の値 = 現在地の見た目 bg-secondary) */
function navLinkClass(isCurrent: boolean) {
    return cn(
        'inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-medium',
        'hover:bg-accent hover:text-accent-foreground',
        isCurrent && 'bg-secondary text-secondary-foreground',
    );
}

/** リンク 1 つ(count = 名前の後の件数。出さないなら null) */
type NavLink = {
    key: string;
    href: string;
    isCurrent: boolean;
    text: string;
    count: number | null;
};

/**
 * リンクの並び 1 つ(今の値に aria-current。部分の再読み込み)。
 */
function NavLinks({ label, links }: { label: string; links: NavLink[] }) {
    return (
        <nav aria-label={label} className="flex flex-wrap items-center gap-1">
            {links.map((link) => (
                <Link
                    key={link.key}
                    href={link.href}
                    only={CASE_LIST_PROPS}
                    preserveState
                    aria-current={link.isCurrent ? 'page' : undefined}
                    className={navLinkClass(link.isCurrent)}
                >
                    {link.text}
                    {link.count !== null && (
                        <span className="font-num tabular-nums">
                            {link.count}
                        </span>
                    )}
                </Link>
            ))}
        </nav>
    );
}

/**
 * 案件一覧のタブ「一時保存 N」「未処理 N」「未確認 N」「処理済」「確認済」「参照」と、処理済・確認済・参照のときだけの
 * 「未完了」「完了」の切替(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節)。検索の語は移った先にも引き継ぐ。
 */
export function CaseListTabs({
    query,
    counts,
}: {
    query: CaseListQuery;
    counts: WorkflowCaseIndexProps['counts'];
}) {
    return (
        <div className="flex flex-col gap-3">
            <NavLinks
                label="申請のタブ"
                links={CASE_TABS.map(({ tab, label }) => ({
                    key: tab,
                    href: caseListUrl({ ...query, tab, scope: 'active' }),
                    isCurrent: tab === query.tab,
                    text: label,
                    count: countOf(tab, counts),
                }))}
            />
            {SCOPED_TABS.includes(query.tab) && (
                <NavLinks
                    label="未完了と完了の切替"
                    links={SCOPES.map(({ scope, label }) => ({
                        key: scope,
                        href: caseListUrl({ ...query, scope }),
                        isCurrent: scope === query.scope,
                        text: label,
                        count: null,
                    }))}
                />
            )}
        </div>
    );
}
