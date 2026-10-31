import { StepAssigneeRange } from '@/components/Workflow/StepAssigneeRange';
import { StepCard } from '@/components/Workflow/StepCard';
import { StepChoiceSelect } from '@/components/Workflow/StepChoiceSelect';
import { StepNumberField } from '@/components/Workflow/StepNumberField';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import {
    DECISION_LABELS,
    DECISION_LINE,
    HELP,
    requiredCountText,
} from '@/lib/Workflow/stepCardTexts';
import type { WorkflowDecisionRule } from '@/types';

const DECISION_CHOICES = (
    Object.keys(DECISION_LABELS) as WorkflowDecisionRule[]
).map((value) => ({ value, label: DECISION_LABELS[value] }));

/**
 * カード 4「合議の人数と可決の条件」(縦配置だけ): 人数の最小・最大(カード 1 と同じ値)・可決の条件(全員・人数・割合)と、
 * 選んだ条件の欄だけを入力できる必要な人数・必要な割合。必要な人数の下に、入力に応じた状態の文を出す。
 */
export function StepDecisionCard({ form }: { form: StepFormApi }) {
    const { values, errors, set } = form;
    const rule = values.decisionRule;

    return (
        <StepCard
            id="step-card-decision"
            number={4}
            heading="合議の人数と可決の条件"
            line={DECISION_LINE}
        >
            <StepAssigneeRange idPrefix="step-decision" form={form} />
            <StepChoiceSelect<WorkflowDecisionRule>
                id="step-decision-rule"
                label="可決の条件"
                value={rule}
                choices={DECISION_CHOICES}
                error={errors.decision_rule}
                onChange={(value) => set('decisionRule', value)}
            />
            <div className="grid grid-cols-2 gap-4">
                <StepNumberField
                    id="step-decision-count"
                    label="必要な人数"
                    value={values.countValue}
                    isDisabled={rule !== 'count'}
                    hint={
                        requiredCountText(rule, values.countValue) ?? undefined
                    }
                    error={rule === 'count' ? errors.decision_value : undefined}
                    onChange={(value) => set('countValue', value)}
                />
                <StepNumberField
                    id="step-decision-ratio"
                    label="必要な割合"
                    help={HELP.ratio}
                    value={values.ratioValue}
                    isDisabled={rule !== 'ratio'}
                    error={rule === 'ratio' ? errors.decision_value : undefined}
                    onChange={(value) => set('ratioValue', value)}
                />
            </div>
        </StepCard>
    );
}
