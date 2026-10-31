import type { ReactNode } from 'react';
import { DetailItem } from '@/components/DetailItem';
import { DueCell, ElapsedCell } from '@/components/Workflow/CaseListCells';
import {
    CaseStateBadge,
    HoldBadge,
} from '@/components/Workflow/WorkflowStatusBadge';
import { EMPTY_VALUE } from '@/lib/recordValues';
import { progressItemKeysOf, reachedDateOf } from '@/lib/Workflow/caseDetail';
import type { ProgressItemKey } from '@/lib/Workflow/caseDetail';
import { formatDateOf, personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowCaseShowProps } from '@/types';

type CaseData = WorkflowCaseShowProps['case'];

/**
 * 基本情報の 1 行(dt・dd)。
 */
function Item({ label, children }: { label: string; children: ReactNode }) {
    return (
        <DetailItem name={label} valueClassName="wrap-anywhere">
            {children}
        </DetailItem>
    );
}

/** 日付・数の表示(等幅の数字) */
function Num({ children }: { children: ReactNode }) {
    return <span className="font-num tabular-nums">{children}</span>;
}

/**
 * 申請の識別(申請番号・申請名・状態の札)。
 */
function IdentityItems({ data }: { data: CaseData }) {
    return (
        <>
            <Item label="申請番号">
                <Num>{data.number}</Num>
            </Item>
            <Item label="申請名">{data.name}</Item>
            <Item label="状態">
                <CaseStateBadge
                    state={data.state}
                    completionReason={data.completionReason}
                    currentNodeState={data.currentSummary?.state ?? null}
                />
            </Item>
        </>
    );
}

/**
 * 申請の元(フロー・申請基準日・申請者・申請日・担当組織)。
 */
function ApplicationItems({ data }: { data: CaseData }) {
    return (
        <>
            <Item label="フロー">
                {data.flow.name}
                <span className="ml-2 font-num text-muted-foreground tabular-nums">{`版 ${data.version}`}</span>
            </Item>
            <Item label="申請基準日">
                <Num>{data.baseDate.replaceAll('-', '/')}</Num>
            </Item>
            <Item label="申請者">{personName(data.applicant)}</Item>
            <Item label="申請日">
                <Num>{formatDateOf(data.appliedAt)}</Num>
            </Item>
            <Item label="担当組織">
                {data.department === null
                    ? EMPTY_VALUE
                    : personName(data.department)}
            </Item>
        </>
    );
}

/**
 * 現在のステップ(保留中の札つき。完了のときは「—」)。
 */
function CurrentStep({ data }: { data: CaseData }) {
    if (data.currentSummary === null) {
        return EMPTY_VALUE;
    }

    return (
        <>
            {data.currentSummary.name}
            {data.currentSummary.state === 'hold' && (
                <>
                    {' '}
                    <HoldBadge />
                </>
            )}
        </>
    );
}

/** 進み具合の項目の見出しと値(並びと出し分けは {@link progressItemKeysOf}) */
const PROGRESS_ITEMS: Record<
    ProgressItemKey,
    { label: string; value: (data: CaseData) => ReactNode }
> = {
    currentStep: {
        label: '現在のステップ',
        value: (data) => <CurrentStep data={data} />,
    },
    completedOn: {
        label: '完了日',
        value: (data) => (
            <Num>
                {data.completedAt === null
                    ? EMPTY_VALUE
                    : formatDateOf(data.completedAt)}
            </Num>
        ),
    },
    due: {
        label: '期限',
        value: (data) => <DueCell deadline={data.deadline} />,
    },
    reachedOn: {
        label: '到達日',
        value: (data) => (
            <Num>{reachedDateOf(data.deadline) ?? EMPTY_VALUE}</Num>
        ),
    },
    elapsedDays: {
        label: '経過日数',
        value: (data) => <ElapsedCell deadline={data.deadline} />,
    },
};

/**
 * 進み具合: 現在のステップの後に、未完了は期限・到達日・経過日数、完了は完了日。
 */
function ProgressItems({ data }: { data: CaseData }) {
    return (
        <>
            {progressItemKeysOf(data.state).map((key) => (
                <Item key={key} label={PROGRESS_ITEMS[key].label}>
                    {PROGRESS_ITEMS[key].value(data)}
                </Item>
            ))}
        </>
    );
}

/**
 * 基本情報の面(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節): 状態の札(現在のステップがすべて保留中なら「保留中」)と、
 * 現在のステップの後に、未完了は期限・到達日・経過日数、完了は完了日。
 */
export function CaseBasicInfo({ data }: { data: CaseData }) {
    return (
        <section
            aria-labelledby="case-basic-heading"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <h2 id="case-basic-heading" className="text-[15px] font-semibold">
                基本情報
            </h2>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm md:grid-cols-3">
                <IdentityItems data={data} />
                <ApplicationItems data={data} />
                <ProgressItems data={data} />
            </dl>
        </section>
    );
}
