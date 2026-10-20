import { CaseRowsTable } from '@/components/Workflow/CaseRowsTable';
import type { WorkflowCaseShowProps } from '@/types';

/** 群の見出し(業務モジュールに依らない一般名 = 設計/ワークフロー/画面.md 5 節) */
const GROUPS = [
    ['sameRecord', '同じレコードの申請'],
    ['referenced', '参照先のレコードの申請'],
    ['referencing', '参照元のレコードの申請'],
] as const;

/**
 * 関連する案件(docs の 設計/ワークフロー/画面.md 5 節。W-4): 群ごとに見出しと表(案件番号・案件名・フロー・申請者・申請日・状態)。空の群は出さず、
 * 3 つとも空なら「関連する申請はありません」。
 */
export function RelatedCases({
    related,
}: {
    related: WorkflowCaseShowProps['related'];
}) {
    const groups = GROUPS.filter(([key]) => related[key].length > 0);

    return (
        <section
            aria-labelledby="case-related-heading"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <h2 id="case-related-heading" className="text-[15px] font-semibold">
                関連する申請
            </h2>
            {groups.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    関連する申請はありません
                </p>
            )}
            {groups.map(([key, heading]) => (
                <div key={key} className="flex flex-col gap-2">
                    <h3 className="text-sm font-medium">{heading}</h3>
                    <CaseRowsTable
                        rows={related[key]}
                        emptyText=""
                        label={heading}
                    />
                </div>
            ))}
        </section>
    );
}
