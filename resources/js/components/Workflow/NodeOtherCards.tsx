import { NodeAttachmentCard } from '@/components/Workflow/NodeAttachmentCard';
import { StepDecisionCard } from '@/components/Workflow/StepDecisionCard';
import { StepPreventsCard } from '@/components/Workflow/StepPreventsCard';
import { StepSendBackCard } from '@/components/Workflow/StepSendBackCard';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { cardOrderOf, type NodeCard } from '@/lib/Workflow/stepForm';
import type { StepFields } from '@/lib/Workflow/stepFields';
import type { WorkflowStepSettings } from '@/types';

/**
 * カード 1 の後のカード。出すカードと番号は cardOrderOf の並びで決める
 * (設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節、設計/ワークフロー/申請の周辺/画面.md の 4 節)。
 */
export function NodeOtherCards({
    node,
    fields,
    form,
}: {
    node: WorkflowStepSettings;
    fields: StepFields;
    form: StepFormApi;
}) {
    const order = cardOrderOf(node);
    const has = (card: NodeCard) => order.includes(card);

    return (
        <>
            {has('prevents') && (
                <StepPreventsCard kind={node.kind} form={form} />
            )}
            {node.sendBack !== null && (
                <StepSendBackCard
                    candidates={node.sendBack.candidates}
                    form={form}
                />
            )}
            {has('decision') && (
                <StepDecisionCard fields={fields.decision} form={form} />
            )}
            {has('attachment') && (
                <NodeAttachmentCard
                    number={order.indexOf('attachment') + 1}
                    form={form}
                />
            )}
        </>
    );
}
