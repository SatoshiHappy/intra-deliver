import { CaseRowsTable } from '@/components/Workflow/CaseRowsTable';
import type { WorkflowCaseShowProps } from '@/types';

/**
 * つながる申請(docs の 設計/ワークフロー/画面/申請と案件.md 5 節。W-4): 群ごとに見出し(サーバーが作った文字列のまま)と表
 * (申請番号・申請名・フロー・申請者・申請日・状態)。どの群も無ければ「つながる申請はありません。」。
 */
export function RelatedCases({
    related,
}: {
    related: WorkflowCaseShowProps['related'];
}) {
    return (
        <section
            aria-labelledby="case-related-heading"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <h2 id="case-related-heading" className="text-[15px] font-semibold">
                つながる申請
            </h2>
            {related.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    つながる申請はありません。
                </p>
            )}
            {related.map((group, index) => (
                <div
                    key={`${index}-${group.heading}`}
                    className="flex flex-col gap-2"
                >
                    <h3 className="text-sm font-medium">{group.heading}</h3>
                    <CaseRowsTable
                        rows={group.cases}
                        emptyText=""
                        label={group.heading}
                    />
                </div>
            ))}
        </section>
    );
}
