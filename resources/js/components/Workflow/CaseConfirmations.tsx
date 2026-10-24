import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { StatusBadge } from '@/components/StatusBadge';
import { CaseConfirmButton } from '@/components/Workflow/CaseConfirmButton';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import {
    CONFIRMATION_BADGES,
    confirmationNoteOf,
} from '@/lib/Workflow/caseDetail';
import { personName } from '@/lib/Workflow/workflowLabels';
import type {
    WorkflowCaseConfirmation,
    WorkflowConfirmationProgress,
} from '@/types';

type Person = WorkflowCaseConfirmation['people'][number];

/**
 * 確認の状態の札(語は色だけで伝えない。省略・確認の行なしは札にせず文字だけ)。
 */
function ConfirmationStatus({
    status,
}: {
    status: WorkflowConfirmationProgress;
}) {
    const { label, tone } = CONFIRMATION_BADGES[status];

    return tone === null ? (
        <span className="text-muted-foreground">{label}</span>
    ) : (
        <StatusBadge label={label} tone={tone} />
    );
}

/**
 * 確認する人 1 人の行(ステップ名・名前と所属(自分に「自分」の札)・状態・確認日時)。
 */
function PersonRow({ stepName, person }: { stepName: string; person: Person }) {
    return (
        <TableRow>
            <TableCell>{stepName}</TableCell>
            <TableCell className="whitespace-normal">
                <span className="flex flex-wrap items-center gap-2">
                    <span>{personName(person.person)}</span>
                    {person.department !== null && (
                        <span className="text-xs text-muted-foreground">
                            {person.department}
                        </span>
                    )}
                    {person.isMe && <Badge variant="secondary">自分</Badge>}
                </span>
            </TableCell>
            <TableCell>
                <ConfirmationStatus status={person.status} />
            </TableCell>
            <TableCell className="font-num tabular-nums">
                {person.confirmedAt === null
                    ? EMPTY_VALUE
                    : formatDatetime(person.confirmedAt)}
            </TableCell>
        </TableRow>
    );
}

/**
 * ステップの行(確認する人ごとに 1 行。人がいなければ「—」の 1 行)。
 */
function StepRows({ step }: { step: WorkflowCaseConfirmation }) {
    if (step.people.length === 0) {
        return (
            <TableRow>
                <TableCell>{step.stepName}</TableCell>
                <TableCell>{EMPTY_VALUE}</TableCell>
                <TableCell>{EMPTY_VALUE}</TableCell>
                <TableCell>{EMPTY_VALUE}</TableCell>
            </TableRow>
        );
    }

    return step.people.map((person) => (
        <PersonRow
            key={`${step.nodeId}-${person.person.id}`}
            stepName={step.stepName}
            person={person}
        />
    ));
}

/**
 * 確認の表(ステップごとに確認する人の行を並べる)。
 */
function ConfirmationTable({
    confirmations,
}: {
    confirmations: WorkflowCaseConfirmation[];
}) {
    return (
        <Table aria-label="確認の状況">
            <TableHeader>
                <TableRow>
                    <TableHead>ステップ</TableHead>
                    <TableHead>確認する人</TableHead>
                    <TableHead>状態</TableHead>
                    <TableHead>確認日時</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {confirmations.map((step) => (
                    <StepRows key={step.nodeId} step={step} />
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * 確認の面(確認する人を持つステップがあるときだけ。docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節): 1 行の説明・表・
 * 確認できるとき(canConfirm)は面の下に主ボタン「確認」。確認が開く前のステップの人は、展開した人を表示だけに出す(サーバーが渡す)。
 */
export function CaseConfirmations({
    caseId,
    confirmations,
    canConfirm,
}: {
    caseId: number;
    confirmations: WorkflowCaseConfirmation[];
    canConfirm: boolean;
}) {
    if (confirmations.length === 0) {
        return null;
    }

    const isConfirmer = confirmations.some((step) =>
        step.people.some((person) => person.isMe),
    );

    return (
        <section
            aria-labelledby="case-confirmations-heading"
            className="flex flex-col gap-3 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <h2
                id="case-confirmations-heading"
                className="text-[15px] font-semibold"
            >
                確認
            </h2>
            <p className="text-sm text-muted-foreground">
                {confirmationNoteOf(isConfirmer)}
            </p>
            <ConfirmationTable confirmations={confirmations} />
            {canConfirm && (
                <div>
                    <CaseConfirmButton caseId={caseId} variant="default" />
                </div>
            )}
        </section>
    );
}
