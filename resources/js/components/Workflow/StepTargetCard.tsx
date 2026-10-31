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
import type { TargetField } from '@/lib/Workflow/stepFields';
import type {
    WorkflowAdminFlowShowProps,
    WorkflowNodeKind,
    WorkflowTargetKind,
} from '@/types';

/**
 * 指定の方法(Select。選べる指定の方法 = 種類で決まる)。
 */
function TargetKindSelect({
    kind,
    form,
}: {
    kind: WorkflowNodeKind;
    form: StepFormApi;
}) {
    return (
        <StepChoiceSelect<WorkflowTargetKind>
            id="step-target-kind"
            label="指定の方法"
            value={form.values.targetKind}
            choices={targetKindChoices(kind).map((value) => ({
                value,
                label: TARGET_KIND_LABELS[value],
            }))}
            error={form.errors.target_kind}
            onChange={(value) => form.set('targetKind', value)}
        />
    );
}

/**
 * カード 1「誰が承認するか」(申請は「誰が申請できるか」): 指定の方法(Select)と、その方法の欄。見出しの横の「?」の説明は指定の方法で切り替わる(保存の前)。
 * 出す欄は fields(定義の管理の画面.md の 3.3 節のデシジョン表)。指定の方法は選べるのが 2 つ以上(承認)のときだけ出す
 * (申請は対象者で指定だけ、横配置・縦配置は処理の時に指定だけ)。
 */
export function StepTargetCard({
    kind,
    fields,
    form,
    options,
}: {
    kind: WorkflowNodeKind;
    fields: TargetField[];
    form: StepFormApi;
    options: WorkflowAdminFlowShowProps['options'];
}) {
    const has = (field: TargetField) => fields.includes(field);

    return (
        <StepCard
            id="step-card-target"
            number={1}
            heading={targetHeading(kind)}
            help={targetLine(kind, form.values.targetKind)}
        >
            {has('targetKind') && <TargetKindSelect kind={kind} form={form} />}
            {has('subjects') && <FixedFields form={form} />}
            {has('relative') && (
                <RelativeFields
                    form={form}
                    companyPosts={options.companyPosts}
                />
            )}
            {has('designator') && (
                <DesignatedFields
                    form={form}
                    designators={options.designators}
                    hasRange={has('range')}
                />
            )}
        </StepCard>
    );
}
