import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { CaseDiffTable } from '@/components/Workflow/CaseDiffTable';
import { ProcessedBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { formatDatetime } from '@/lib/recordValues';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { RecordModuleDefinition, WorkflowCaseHistory } from '@/types';

/**
 * 履歴の 1 行(ステップ名・処理の種別・処理者・処理日時と、下にコメント。差戻しは差戻し先、再申請は差分の開閉 = 既定は閉じる。承認の指定は
 * コメントの下に「指定: {指定されたステップ} = {名前}」、0 人の指定は「= 省略」= ルートの深さ/画面.md 7 節)。
 */
function HistoryItem({
    history,
    module,
}: {
    history: WorkflowCaseHistory;
    module: RecordModuleDefinition;
}) {
    const [isDiffOpen, setDiffOpen] = useState(false);
    const diffId = `case-history-${history.id}-diff`;

    return (
        <li className="flex flex-col gap-2 border-b py-3 last:border-b-0">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                <span className="font-medium">{history.nodeName}</span>
                <ProcessedBadge action={history.action} />
                <span>{personName(history.actor)}</span>
                <span className="font-num text-muted-foreground tabular-nums">
                    {formatDatetime(history.at)}
                </span>
                {history.sendBackNodeName !== null && (
                    <span className="text-muted-foreground">
                        差戻し先: {history.sendBackNodeName}
                    </span>
                )}
                {history.diff !== null && (
                    <Button
                        variant="link"
                        size="sm"
                        className="h-auto p-0"
                        aria-expanded={isDiffOpen}
                        aria-controls={diffId}
                        onClick={() => setDiffOpen((open) => !open)}
                    >
                        {isDiffOpen ? '差分を非表示' : '差分を表示'}
                    </Button>
                )}
            </div>
            {history.comment !== null && (
                <p className="text-sm wrap-anywhere whitespace-pre-wrap">
                    {history.comment}
                </p>
            )}
            {history.assignments.map((assignment) => (
                <p
                    key={assignment.nodeName}
                    className="text-sm wrap-anywhere text-muted-foreground"
                >
                    {`指定: ${assignment.nodeName} = ${assignment.people.length === 0 ? '省略' : assignment.people.map(personName).join('、')}`}
                </p>
            ))}
            {history.diff !== null && isDiffOpen && (
                <div id={diffId}>
                    <CaseDiffTable
                        diff={history.diff}
                        baseAt={history.diffBaseAt}
                        module={module}
                    />
                </div>
            )}
        </li>
    );
}

/**
 * 履歴(docs の 設計/ワークフロー/画面.md 5 節): 見出しの右に「昇順」「降順」の切替(画面の中だけで並べ替える。既定は昇順)。
 * histories は古い順で受け取る。
 */
export function CaseHistoryList({
    histories,
    module,
}: {
    histories: WorkflowCaseHistory[];
    module: RecordModuleDefinition;
}) {
    const [isDescending, setDescending] = useState(false);
    const ordered = isDescending ? [...histories].reverse() : histories;

    return (
        <section
            aria-labelledby="case-histories-heading"
            className="flex flex-col gap-3 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex items-center justify-between gap-4">
                <h2
                    id="case-histories-heading"
                    className="text-[15px] font-semibold"
                >
                    履歴
                </h2>
                <div
                    className="flex items-center gap-1"
                    role="group"
                    aria-label="履歴の並び"
                >
                    <Button
                        variant={isDescending ? 'ghost' : 'secondary'}
                        size="sm"
                        aria-pressed={!isDescending}
                        onClick={() => setDescending(false)}
                    >
                        昇順
                    </Button>
                    <Button
                        variant={isDescending ? 'secondary' : 'ghost'}
                        size="sm"
                        aria-pressed={isDescending}
                        onClick={() => setDescending(true)}
                    >
                        降順
                    </Button>
                </div>
            </div>
            <ol className="flex flex-col">
                {ordered.map((history) => (
                    <HistoryItem
                        key={history.id}
                        history={history}
                        module={module}
                    />
                ))}
            </ol>
        </section>
    );
}
