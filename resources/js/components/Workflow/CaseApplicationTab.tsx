import { CaseBasicInfo } from '@/components/Workflow/CaseBasicInfo';
import { CaseConfirmations } from '@/components/Workflow/CaseConfirmations';
import { CaseFlowInfo } from '@/components/Workflow/CaseFlowInfo';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { CaseHistoryList } from '@/components/Workflow/CaseHistoryList';
import { CaseOperationArea } from '@/components/Workflow/CaseOperationArea';
import { RelatedCases } from '@/components/Workflow/RelatedCases';
import type { WorkflowCaseShowProps } from '@/types';

/**
 * 左のタブ「申請」: 申請に固有の面を並べる。面の並びと出す条件は
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 3 節。面の枠はタブの枠(カード)が持つので、面自身は枠を持たず、
 * 面の間の区切りの線と上下の余白はこの親が持つ(モック 4g)。
 */
export function CaseApplicationTab({
    onOpenAction,
    ...props
}: WorkflowCaseShowProps & { onOpenAction: OpenCaseAction }) {
    return (
        <div className="flex flex-col divide-y *:py-6 *:first:pt-0 *:last:pb-0">
            <CaseBasicInfo data={props.case} />
            <CaseFlowInfo
                nodes={props.nodes}
                edges={props.edges}
                councils={props.councils}
            />
            <CaseHistoryList histories={props.histories} />
            <CaseConfirmations
                caseId={props.case.id}
                confirmations={props.confirmations}
                canConfirm={props.can.confirm}
            />
            <RelatedCases related={props.related} />
            <CaseOperationArea {...props} onOpenAction={onOpenAction} />
        </div>
    );
}
