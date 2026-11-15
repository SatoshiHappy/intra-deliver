import { CaseConfirmations } from '@/components/Workflow/CaseConfirmations';
import { CaseFlowInfo } from '@/components/Workflow/CaseFlowInfo';
import { CaseTabFaces } from '@/components/Workflow/CaseTabFaces';
import type { WorkflowCaseShowProps } from '@/types';

/** 左のタブ「フロー」(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 3 節) */
export function CaseFlowTab(props: WorkflowCaseShowProps) {
    return (
        <CaseTabFaces>
            <CaseFlowInfo
                nodes={props.nodes}
                edges={props.edges}
                councils={props.councils}
            />
            <CaseConfirmations
                caseId={props.case.id}
                confirmations={props.confirmations}
                canConfirm={props.can.confirm}
            />
        </CaseTabFaces>
    );
}
