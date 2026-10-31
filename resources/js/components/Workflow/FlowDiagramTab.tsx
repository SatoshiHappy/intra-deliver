import { FlowStepButtons } from '@/components/Workflow/FlowStepButtons';
import { FlowVersionSelect } from '@/components/Workflow/FlowVersionSelect';
import { StepSettingsPane } from '@/components/Workflow/StepSettingsPane';
import { WorkflowDiagram } from '@/components/Workflow/WorkflowDiagram';
import { versionLabel } from '@/lib/Workflow/flowDefinitionTexts';
import { visitFlowShow, visitFlowStep } from '@/lib/Workflow/flowDefinitionUrl';
import type { WorkflowAdminFlowShowProps } from '@/types';

type Props = Pick<
    WorkflowAdminFlowShowProps,
    'versions' | 'selectedVersion' | 'diagram' | 'selectedNode' | 'options'
> & { flowCode: string };

/**
 * 左の面の見出しと版の選択(版を替えても同じコードのステップを選んだままにする)。
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
        <div className="flex flex-wrap items-center justify-between gap-4">
            <h2 id="flow-diagram-heading" className="text-[15px] font-semibold">
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
 * 左の面(見出し・版の選択・押して選べるフロー図・図の下のステップの名前のボタン)。
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
            className="flex min-w-0 flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <DiagramPanelHeader
                flowCode={flowCode}
                versions={versions}
                selectedVersion={selectedVersion}
                nodeCode={selectedNode.code}
            />
            <WorkflowDiagram
                nodes={diagram.nodes}
                edges={diagram.edges}
                label={`${versionLabel(selectedVersion)} のフロー図`}
                selection={{ code: selectedNode.code, onSelect: select }}
            />
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
 */
export function FlowDiagramTab({ options, ...panel }: Props) {
    return (
        <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
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
