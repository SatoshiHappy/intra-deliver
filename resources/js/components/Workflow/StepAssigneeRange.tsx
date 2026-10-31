import { StepNumberField } from '@/components/Workflow/StepNumberField';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';

/**
 * 人数の最小・最大(処理の時に指定のカード 1 と縦配置のカード 4 が同じ値を共有して出す)。idPrefix で欄の id を分ける。
 */
export function StepAssigneeRange({
    idPrefix,
    form,
}: {
    idPrefix: string;
    form: StepFormApi;
}) {
    const { values, errors, set } = form;

    return (
        <div className="grid grid-cols-2 gap-4">
            <StepNumberField
                id={`${idPrefix}-min`}
                label="人数の最小"
                value={values.min}
                error={errors.min_assignees}
                onChange={(value) => set('min', value)}
            />
            <StepNumberField
                id={`${idPrefix}-max`}
                label="人数の最大"
                value={values.max}
                error={errors.max_assignees}
                onChange={(value) => set('max', value)}
            />
        </div>
    );
}
