import { StepAssigneeRange } from '@/components/Workflow/StepAssigneeRange';
import { StepCard } from '@/components/Workflow/StepCard';
import { StepChoiceToggle } from '@/components/Workflow/StepChoiceToggle';
import { StepNumberField } from '@/components/Workflow/StepNumberField';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import {
    DECISION_LABELS,
    DECISION_LINE,
    HELP,
} from '@/lib/Workflow/stepCardTexts';
import type { DecisionField } from '@/lib/Workflow/stepFields';
import type { WorkflowDecisionRule } from '@/types';

const DECISION_CHOICES = (
    Object.keys(DECISION_LABELS) as WorkflowDecisionRule[]
).map((value) => ({ value, label: DECISION_LABELS[value] }));

/**
 * 選んだ可決の条件の欄(人数 = 必要な人数、割合 = 必要な割合、全員 = 欄なし)。
 */
function DecisionValueField({
    fields,
    form,
}: {
    fields: DecisionField[];
    form: StepFormApi;
}) {
    const { values, errors, set } = form;

    if (fields.includes('count')) {
        return (
            <StepNumberField
                id="step-decision-count"
                label="必要な人数"
                value={values.countValue}
                error={errors.decision_value}
                onChange={(value) => set('countValue', value)}
            />
        );
    }

    return fields.includes('ratio') ? (
        <StepNumberField
            id="step-decision-ratio"
            label="必要な割合"
            help={HELP.ratio}
            value={values.ratioValue}
            error={errors.decision_value}
            onChange={(value) => set('ratioValue', value)}
        />
    ) : null;
}

/**
 * カード 4「合議の人数と可決の条件」(縦配置だけ): 人数の最小・最大・可決の条件(切り替えボタン。全員・人数・割合)と、
 * 選んだ条件の欄だけ(人数 = 必要な人数、割合 = 必要な割合、全員 = 欄なし)。出す欄は fields(定義の管理の画面.md の 3.3 節のデシジョン表)。
 */
export function StepDecisionCard({
    fields,
    form,
}: {
    fields: DecisionField[];
    form: StepFormApi;
}) {
    const { values, errors, set } = form;
    const has = (field: DecisionField) => fields.includes(field);

    return (
        <StepCard
            id="step-card-decision"
            number={4}
            heading="合議の人数と可決の条件"
            help={DECISION_LINE}
        >
            {has('range') && (
                <StepAssigneeRange idPrefix="step-decision" form={form} />
            )}
            {has('decisionRule') && (
                <StepChoiceToggle<WorkflowDecisionRule>
                    id="step-decision-rule"
                    label="可決の条件"
                    value={values.decisionRule}
                    choices={DECISION_CHOICES}
                    error={errors.decision_rule}
                    onChange={(value) => set('decisionRule', value)}
                />
            )}
            <DecisionValueField fields={fields} form={form} />
        </StepCard>
    );
}
