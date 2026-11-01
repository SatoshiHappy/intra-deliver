import { CaseRowsTable } from '@/components/Workflow/CaseRowsTable';
import type { WorkflowCaseShowProps } from '@/types';

/**
 * つながる申請(設計/ワークフロー/画面/申請と案件.md の 5 節)。群の見出しはサーバーが作った文字列のまま出す。
 */
export function RelatedCases({
    related,
}: {
    related: WorkflowCaseShowProps['related'];
}) {
    return (
        <section
            aria-labelledby="case-related-heading"
            className="flex flex-col gap-4"
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
