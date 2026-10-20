import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
} from '@/components/Workflow/CaseActionDialog';
import { CaseContent } from '@/components/Workflow/CaseContent';
import { CaseFlowInfo } from '@/components/Workflow/CaseFlowInfo';
import { CaseHistoryList } from '@/components/Workflow/CaseHistoryList';
import { RelatedCases } from '@/components/Workflow/RelatedCases';
import { CaseStateBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { EMPTY_VALUE } from '@/lib/recordValues';
import { formatDateOf, personName } from '@/lib/Workflow/workflowLabels';
import { index, show } from '@/routes/workflow/cases';
import { create as reapply } from '@/routes/workflow/reapplications';
import type { WorkflowCaseShowProps } from '@/types';

type Props = WorkflowCaseShowProps;

/** 見出しの右のステップごとのダイアログの処理(並びの順。引戻し・再申請・取止めは案件に 1 つ) */
const NODE_OPERATIONS = [
    'approve',
    'approveEarly',
    'reject',
    'sendBack',
] as const satisfies CaseOperation[];

/**
 * 基本情報の 1 行(dt・dd)。
 */
function Item({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="wrap-anywhere">{children}</dd>
        </div>
    );
}

/**
 * 案件の詳細(docs の 設計/ワークフロー/画面.md 5 節、ルートの深さ/画面.md 7 節): 見出し = 案件名、右に操作のボタン(承認・承認終了・否認・差戻しはどれかのステップで
 * 許されるときだけ = nodeActions、引戻し・再申請・取止めは can。押すと 6 節のダイアログ、再申請は再申請の画面へ)。基本情報に申請基準日と版、現在のステップは「、」で並べる。
 * 面 = 基本情報・申請内容・フロー情報(図と表の切替・合議の進み具合)・履歴・関連する案件。本文の幅は上限なし(骨格の本文の上限まで)。
 */
export default function CaseShow({
    case: workflowCase,
    content,
    nodes,
    edges,
    councils,
    histories,
    related,
    can,
    nodeActions,
    pullBackNodes,
    commentMaxLength,
}: Props) {
    const { openAction, dialogProps } = useCaseActionDialog();
    const nodesOf = (
        operation: (typeof NODE_OPERATIONS)[number],
    ): CaseActionTarget['nodes'] =>
        nodeActions
            .filter((action) => action[operation])
            .map((action) => ({
                id: action.nodeId,
                name: action.name,
                blockedReason: action.blockedReason,
                sendBackTargets: action.sendBackTargets,
                sendBackRestricted: action.sendBackRestricted,
                isCouncilVote: action.isCouncilVote,
                designations: action.designations,
            }));
    const operations = [
        ...NODE_OPERATIONS.filter((operation) => nodesOf(operation).length > 0),
        ...(can.pullBack ? (['pullBack'] as const) : []),
    ];
    const hasButtons = operations.length > 0 || can.reapply || can.withdraw;
    const targetOf = (operation: CaseOperation): CaseActionTarget => ({
        operation,
        caseId: workflowCase.id,
        caseName: workflowCase.name,
        revision: workflowCase.revision,
        commentMaxLength,
        nodes:
            operation === 'withdraw'
                ? []
                : operation === 'pullBack'
                  ? pullBackNodesOf(pullBackNodes)
                  : nodesOf(operation),
    });

    return (
        <>
            <Head title={workflowCase.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold wrap-anywhere">
                        {workflowCase.name}
                    </h1>
                    {hasButtons && (
                        <div className="flex flex-wrap items-center gap-2">
                            {operations.map((operation) => (
                                <CaseActionButton
                                    key={operation}
                                    target={targetOf(operation)}
                                    placement="header"
                                    onOpen={openAction}
                                />
                            ))}
                            {can.reapply && (
                                <Button asChild>
                                    <Link href={reapply(workflowCase.id)}>
                                        再申請
                                    </Link>
                                </Button>
                            )}
                            {can.withdraw && (
                                <CaseActionButton
                                    target={targetOf('withdraw')}
                                    placement="header"
                                    onOpen={openAction}
                                />
                            )}
                        </div>
                    )}
                </div>
                <section
                    aria-labelledby="case-basic-heading"
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <h2
                        id="case-basic-heading"
                        className="text-[15px] font-semibold"
                    >
                        基本情報
                    </h2>
                    <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm md:grid-cols-3">
                        <Item label="申請番号">
                            <span className="font-num tabular-nums">
                                {workflowCase.number}
                            </span>
                        </Item>
                        <Item label="申請名">{workflowCase.name}</Item>
                        <Item label="状態">
                            <CaseStateBadge
                                state={workflowCase.state}
                                completionReason={workflowCase.completionReason}
                                currentNodeState={
                                    workflowCase.currentSummary?.state ?? null
                                }
                            />
                        </Item>
                        <Item label="フロー">
                            {workflowCase.flow.name}
                            <span className="ml-2 font-num text-muted-foreground tabular-nums">{`版 ${workflowCase.version}`}</span>
                        </Item>
                        <Item label="申請基準日">
                            <span className="font-num tabular-nums">
                                {workflowCase.baseDate.replaceAll('-', '/')}
                            </span>
                        </Item>
                        <Item label="申請者">
                            {personName(workflowCase.applicant)}
                        </Item>
                        <Item label="申請日">
                            <span className="font-num tabular-nums">
                                {formatDateOf(workflowCase.appliedAt)}
                            </span>
                        </Item>
                        <Item label="担当組織">
                            {workflowCase.department === null
                                ? EMPTY_VALUE
                                : personName(workflowCase.department)}
                        </Item>
                        <Item label="現在のステップ">
                            {workflowCase.state === 'completed' ||
                            workflowCase.currentSummary === null
                                ? EMPTY_VALUE
                                : workflowCase.currentSummary.name}
                        </Item>
                        {workflowCase.state === 'completed' && (
                            <Item label="完了日">
                                <span className="font-num tabular-nums">
                                    {workflowCase.completedAt === null
                                        ? EMPTY_VALUE
                                        : formatDateOf(
                                              workflowCase.completedAt,
                                          )}
                                </span>
                            </Item>
                        )}
                    </dl>
                </section>
                <CaseContent content={content} />
                <CaseFlowInfo nodes={nodes} edges={edges} councils={councils} />
                <CaseHistoryList
                    histories={histories}
                    module={content.module}
                />
                <RelatedCases related={related} />
            </div>
            {/* 処理のダイアログは描き分けの外に 1 つ(誤りの後に can が偽になっても閉じない = CaseActionDialog) */}
            <CaseActionDialog {...dialogProps} />
        </>
    );
}

CaseShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: '申請一覧', href: index() },
        { title: props.case.name, href: show(props.case.id) },
    ],
});
