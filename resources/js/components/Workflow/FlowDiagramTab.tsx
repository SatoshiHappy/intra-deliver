import type { CSSProperties } from 'react';
import { FlowStepButtons } from '@/components/Workflow/FlowStepButtons';
import { FlowVersionSelect } from '@/components/Workflow/FlowVersionSelect';
import { StepSettingsPane } from '@/components/Workflow/StepSettingsPane';
import { WorkflowDiagram } from '@/components/Workflow/WorkflowDiagram';
import { useFillHeight } from '@/hooks/use-fill-height';
import { versionLabel } from '@/lib/Workflow/flowDefinitionTexts';
import { visitFlowShow, visitFlowStep } from '@/lib/Workflow/flowDefinitionUrl';
import { cn } from '@/lib/utils';
import type { WorkflowAdminFlowShowProps } from '@/types';

/** ページの下の余白(px。ページの p-4)と、図の枠と右の欄の最小の高さ(px。高さ 700 の画面でも図とカードが使える高さ) */
const PAGE_BOTTOM_GAP = 16;
const MIN_PANE_HEIGHT = 420;

type Props = Pick<
    WorkflowAdminFlowShowProps,
    'versions' | 'selectedVersion' | 'diagram' | 'selectedNode' | 'options'
> & { flowCode: string };

/**
 * 左の面の版の選択(見出し「フロー図」はタブと同じ名前なので読み上げだけ)。版を替えても同じコードのステップを選んだままにする。
 */
function DiagramPanelHeader({
    flowCode,
    versions,
    selectedVersion,
    nodeCode,
}: {
    flowCode: string;
    versions: Props['versions'];
    selectedVersion: number;
    nodeCode: string;
}) {
    return (
        <div className="flex flex-wrap items-center justify-end gap-4">
            <h2 id="flow-diagram-heading" className="sr-only">
                フロー図
            </h2>
            <FlowVersionSelect
                versions={versions}
                selected={selectedVersion}
                onChange={(version) =>
                    visitFlowShow(flowCode, {
                        version,
                        tab: 'diagram',
                        node: nodeCode,
                    })
                }
            />
        </div>
    );
}

/**
 * 左の面(読み上げの見出し・版の選択・押して選べるフロー図・図の下のステップの名前のボタン)。
 */
function DiagramPanel({
    flowCode,
    versions,
    selectedVersion,
    diagram,
    selectedNode,
}: Omit<Props, 'options'>) {
    const select = (node: string) =>
        visitFlowStep(flowCode, {
            version: selectedVersion,
            tab: 'diagram',
            node,
        });

    return (
        <section
            aria-labelledby="flow-diagram-heading"
            className={cn(
                'flex min-w-0 flex-col gap-4 rounded-xl border bg-card p-5',
                'text-card-foreground xl:h-full xl:min-h-0',
            )}
        >
            <DiagramPanelHeader
                flowCode={flowCode}
                versions={versions}
                selectedVersion={selectedVersion}
                nodeCode={selectedNode.code}
            />
            <div className="xl:min-h-0 xl:flex-1">
                <WorkflowDiagram
                    nodes={diagram.nodes}
                    edges={diagram.edges}
                    label={`${versionLabel(selectedVersion)} のフロー図`}
                    selection={{ code: selectedNode.code, onSelect: select }}
                    fillsParent
                />
            </div>
            <FlowStepButtons
                nodes={diagram.nodes}
                selectedCode={selectedNode.code}
                onSelect={select}
            />
        </section>
    );
}

/**
 * フロー図のタブ(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.1 節・3.3 節): 左にフロー図の面、
 * 右に図で選んだステップの欄(要約とカード)。選んだステップと版は URL のクエリに残り、部分の再読み込みで切り替わる。
 * 版を替えたときは、同じコードのステップが新しい版にあれば選んだまま(無ければサーバーが申請のステップにする)。
 * 2 列に並ぶ幅(xl)では、左の図の枠と右の欄を画面の高さに合わせる(3.3 節)。低い画面でも図と欄が使えるよう、高さは最小 420px。
 */
export function FlowDiagramTab({ options, ...panel }: Props) {
    const fill = useFillHeight(PAGE_BOTTOM_GAP, MIN_PANE_HEIGHT);
    const style =
        fill.height === null
            ? undefined
            : ({ '--flow-pane-height': `${fill.height}px` } as CSSProperties);

    return (
        <div
            ref={fill.ref}
            style={style}
            className="grid min-w-0 gap-4 xl:h-(--flow-pane-height) xl:grid-cols-[minmax(0,1fr)_360px]"
        >
            <DiagramPanel {...panel} />
            <StepSettingsPane
                flowCode={panel.flowCode}
                version={panel.selectedVersion}
                step={panel.selectedNode}
                options={options}
            />
        </div>
    );
}
