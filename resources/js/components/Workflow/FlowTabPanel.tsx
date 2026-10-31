import { FlowDiagramTab } from '@/components/Workflow/FlowDiagramTab';
import { FlowReferrersTab } from '@/components/Workflow/FlowReferrersTab';
import { FlowVersionsTab } from '@/components/Workflow/FlowVersionsTab';
import type { WorkflowAdminFlowShowProps } from '@/types';

/**
 * 詳細のタブごとの面(フロー図・版・参照者。今のタブの面だけを出す)。
 */
export function FlowTabPanel(props: WorkflowAdminFlowShowProps) {
    const { flow, versions, tab } = props;

    if (tab === 'versions') {
        return (
            <FlowVersionsTab
                flowCode={flow.code}
                flowName={flow.name}
                versions={versions}
                today={props.today}
            />
        );
    }

    if (tab === 'referrers') {
        return (
            <FlowReferrersTab
                flowCode={flow.code}
                rows={props.referrers.rows}
            />
        );
    }

    return (
        <FlowDiagramTab
            flowCode={flow.code}
            versions={versions}
            selectedVersion={props.selectedVersion}
            diagram={props.diagram}
            selectedNode={props.selectedNode}
            options={props.options}
        />
    );
}
