import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/StatusBadge';
import { FlowInfoTable } from '@/components/Workflow/FlowInfoTable';
import { PersonMenu } from '@/components/Workflow/PersonMenu';
import { WorkflowDiagram } from '@/components/Workflow/WorkflowDiagram';
import {
    CaseStepBadge,
    HoldBadge,
} from '@/components/Workflow/WorkflowStatusBadge';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import { personName } from '@/lib/Workflow/workflowLabels';
import type {
    WorkflowCaseDiagramNode,
    WorkflowCouncil,
    WorkflowDiagramEdge,
} from '@/types';

type View = 'diagram' | 'table';

/** 図と表の切替のボタン(既定は図) */
const VIEWS: { value: View; label: string }[] = [
    { value: 'diagram', label: '図' },
    { value: 'table', label: '表' },
];

/**
 * 図の凡例(処理済み・承認待ち・保留中 = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節)。
 * 図の描き方にそろえ、到達していないは札にせず普通の縁と文字。
 */
function Legend() {
    return (
        <ul aria-label="凡例" className="flex flex-wrap items-center gap-2">
            <li>
                <StatusBadge label="処理済み" tone="settled" />
            </li>
            <li>
                <StatusBadge label="承認待ち" tone="pending" />
            </li>
            <li>
                <HoldBadge />
            </li>
            <li>
                <Badge variant="outline" className="text-muted-foreground">
                    到達していない
                </Badge>
            </li>
        </ul>
    );
}

/**
 * 合議の進み具合の子の行。処理担当者の名前は札の「…」つき、所属の組織の名前には付けない(設計/ワークフロー/申請の周辺/メニューと導線.md 2.2)。
 */
function CouncilRowItem({ row }: { row: WorkflowCouncil['rows'][number] }) {
    return (
        <li className="flex items-center gap-3 py-2 text-sm">
            <div className="flex min-w-0 flex-1 flex-col">
                <span className="flex items-center gap-2">
                    <span className="truncate">
                        {row.person === null
                            ? EMPTY_VALUE
                            : personName(row.person)}
                    </span>
                    {row.person !== null && <PersonMenu person={row.person} />}
                    {row.person?.isMe === true && (
                        <Badge variant="secondary">自分</Badge>
                    )}
                </span>
                <span className="truncate text-xs text-muted-foreground">
                    {row.department === null
                        ? EMPTY_VALUE
                        : personName(row.department)}
                    {row.at !== null && (
                        <span className="ml-2 font-num tabular-nums">
                            {formatDatetime(row.at)}
                        </span>
                    )}
                </span>
            </div>
            <CaseStepBadge
                stepState={row.stepState}
                action={row.action}
                className="text-xs"
            />
        </li>
    );
}

/**
 * 合議の進み具合(縦配置が展開中か通過のときだけサーバーが渡す。中身と並び = ルートの深さ/画面/案件の側の画面.md 7 節)。行は {@link CouncilRowItem}。
 */
function CouncilProgress({ council }: { council: WorkflowCouncil }) {
    const headingId = `council-${council.nodeId}-heading`;

    return (
        <section
            aria-labelledby={headingId}
            className="flex flex-col gap-3 rounded-lg border p-4"
        >
            <div className="flex flex-col gap-0.5">
                <span className="text-xs text-muted-foreground">
                    合議の進み具合
                </span>
                <h3 id={headingId} className="text-sm font-semibold">
                    {council.name}
                </h3>
            </div>
            <p className="text-sm">
                <span className="font-num text-2xl font-semibold tabular-nums">
                    {council.approved}
                </span>
                {` 人が承認　必要 ${council.required} 人`}
            </p>
            <p className="text-xs text-muted-foreground">
                {council.passed
                    ? '合議は完了しました。'
                    : `未処理 ${council.pending} 人。${council.required} 人が承認すると、合議は完了します。`}
            </p>
            <ul
                aria-label={`${council.name}の処理担当者`}
                className="flex flex-col divide-y"
            >
                {council.rows.map((row) => (
                    <CouncilRowItem key={row.nodeId} row={row} />
                ))}
            </ul>
        </section>
    );
}

/**
 * 図と表の切替のボタン(押している側を secondary にする)。
 */
function ViewSwitch({
    view,
    onChange,
}: {
    view: View;
    onChange: (view: View) => void;
}) {
    return (
        <div
            className="flex items-center gap-1"
            role="group"
            aria-label="フロー情報の表示"
        >
            {VIEWS.map((item) => (
                <Button
                    key={item.value}
                    variant={view === item.value ? 'secondary' : 'ghost'}
                    size="sm"
                    aria-pressed={view === item.value}
                    onClick={() => onChange(item.value)}
                >
                    {item.label}
                </Button>
            ))}
        </div>
    );
}

/**
 * フロー情報の面(ルートの深さ/画面/案件の側の画面.md 7 節): 見出しの右に凡例(図のとき)と「図」「表」の切替(既定は図。表は図の文字の代わりで、読み上げとキーボードで
 * 同じ情報を得る)。合議の進み具合は図・表の右に並べる(モック 5)。
 */
export function CaseFlowInfo({
    nodes,
    edges,
    councils,
}: {
    nodes: WorkflowCaseDiagramNode[];
    edges: WorkflowDiagramEdge[];
    councils: WorkflowCouncil[];
}) {
    const [view, setView] = useState<View>('diagram');

    return (
        <section
            aria-labelledby="case-flow-heading"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h2
                    id="case-flow-heading"
                    className="text-[15px] font-semibold"
                >
                    フロー情報
                </h2>
                <div className="flex flex-wrap items-center gap-4">
                    {view === 'diagram' && <Legend />}
                    <ViewSwitch view={view} onChange={setView} />
                </div>
            </div>
            <div
                className={
                    councils.length === 0
                        ? 'min-w-0'
                        : 'grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_320px]'
                }
            >
                <div className="min-w-0">
                    {view === 'diagram' ? (
                        <WorkflowDiagram
                            nodes={nodes}
                            edges={edges}
                            label="フロー図"
                        />
                    ) : (
                        <FlowInfoTable nodes={nodes} />
                    )}
                </div>
                {councils.length > 0 && (
                    <div className="flex flex-col gap-4">
                        {councils.map((council) => (
                            <CouncilProgress
                                key={council.nodeId}
                                council={council}
                            />
                        ))}
                    </div>
                )}
            </div>
        </section>
    );
}
