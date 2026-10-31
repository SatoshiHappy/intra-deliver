import { StepCard } from '@/components/Workflow/StepCard';
import { StepChoiceSelect } from '@/components/Workflow/StepChoiceSelect';
import {
    DesignatedFields,
    FixedFields,
    RelativeFields,
} from '@/components/Workflow/StepTargetFields';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import {
    TARGET_KIND_LABELS,
    targetHeading,
    targetKindChoices,
    targetLine,
} from '@/lib/Workflow/stepCardTexts';
import type {
    WorkflowAdminFlowShowProps,
    WorkflowNodeKind,
    WorkflowTargetKind,
} from '@/types';

/**
 * カード 1「誰が承認するか」(申請は「誰が申請できるか」): 指定の方法(Select)と、その方法の欄。見出しの下の 1 行は指定の方法で切り替わる(保存の前)。
 * 申請は対象者で指定だけ、横配置・縦配置は処理の時に指定だけ。
 */
export function StepTargetCard({
    kind,
    form,
    options,
}: {
    kind: WorkflowNodeKind;
    form: StepFormApi;
    options: WorkflowAdminFlowShowProps['options'];
}) {
    const { values, errors, set } = form;

    return (
        <StepCard
            id="step-card-target"
            number={1}
            heading={targetHeading(kind)}
            line={targetLine(kind, values.targetKind)}
        >
            <StepChoiceSelect<WorkflowTargetKind>
                id="step-target-kind"
                label="指定の方法"
                value={values.targetKind}
                choices={targetKindChoices(kind).map((value) => ({
                    value,
                    label: TARGET_KIND_LABELS[value],
                }))}
                error={errors.target_kind}
                onChange={(value) => set('targetKind', value)}
            />
            {values.targetKind === 'fixed' && <FixedFields form={form} />}
            {values.targetKind === 'relative' && (
                <RelativeFields
                    form={form}
                    companyPosts={options.companyPosts}
                />
            )}
            {values.targetKind === 'designated' && (
                <DesignatedFields
                    form={form}
                    designators={options.designators}
                />
            )}
        </StepCard>
    );
}
