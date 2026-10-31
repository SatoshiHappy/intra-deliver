import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { CaseDiffTable } from '@/components/Workflow/CaseDiffTable';
import { ProcessedBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { formatDatetime } from '@/lib/recordValues';
import {
    actorViewText,
    assignmentNoteOf,
    historyNoteOf,
} from '@/lib/Workflow/caseDetail';
import type { RecordModuleDefinition, WorkflowCaseHistory } from '@/types';

/**
 * 履歴の 1 行の見出し(ステップ名・処理の種別の札・処理実行者・処理日時・差戻し先。再申請は差分の開閉)。
 */
function HistoryHead({
    history,
    isDiffOpen,
    onToggleDiff,
}: {
    history: WorkflowCaseHistory;
    isDiffOpen: boolean;
    onToggleDiff: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span className="font-medium">{history.nodeName}</span>
            <ProcessedBadge action={history.action} />
            <span>{actorViewText(history)}</span>
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
                    aria-controls={diffIdOf(history)}
                    onClick={onToggleDiff}
                >
                    {isDiffOpen ? '差分を非表示' : '差分を表示'}
                </Button>
            )}
        </div>
    );
}

/** 差分の要素の id */
function diffIdOf(history: WorkflowCaseHistory): string {
    return `case-history-${history.id}-diff`;
}

/**
 * コメントと、その下に添える行(担当変更・処理担当者の変更と再展開・戻る・指定の変更の 1 行と、承認の指定。
 * 「指定: {指定されたステップ} = {名前}」、0 人の指定は「= 省略」= 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 7 節)。
 */
function HistoryNotes({ history }: { history: WorkflowCaseHistory }) {
    const note = historyNoteOf(history);
    const notes = [
        ...(note === null ? [] : [note]),
        ...history.assignments.map(assignmentNoteOf),
    ];

    return (
        <>
            {history.comment !== null && (
                <p className="text-sm wrap-anywhere whitespace-pre-wrap">
                    {history.comment}
                </p>
            )}
            {notes.map((line, index) => (
                <p
                    key={`${index}-${line}`}
                    className="text-sm wrap-anywhere text-muted-foreground"
                >
                    {line}
                </p>
            ))}
        </>
    );
}

/**
 * 履歴の 1 行(見出しの下にコメントと添える行、再申請は差分 = 既定は閉じる)。
 */
function HistoryItem({
    history,
    module,
}: {
    history: WorkflowCaseHistory;
    module: RecordModuleDefinition;
}) {
    const [isDiffOpen, setDiffOpen] = useState(false);

    return (
        <li className="flex flex-col gap-2 border-b py-3 last:border-b-0">
            <HistoryHead
                history={history}
                isDiffOpen={isDiffOpen}
                onToggleDiff={() => setDiffOpen((open) => !open)}
            />
            <HistoryNotes history={history} />
            {history.diff !== null && isDiffOpen && (
                <div id={diffIdOf(history)}>
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
 * 履歴(docs の 設計/ワークフロー/画面/申請と案件.md 5 節): 見出しの右に「昇順」「降順」の切替(画面の中だけで並べ替える。既定は昇順)。
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
