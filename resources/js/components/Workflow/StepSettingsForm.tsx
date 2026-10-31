import { UnshownErrors } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import { StepDecisionCard } from '@/components/Workflow/StepDecisionCard';
import { StepPreventsCard } from '@/components/Workflow/StepPreventsCard';
import { StepSendBackCard } from '@/components/Workflow/StepSendBackCard';
import { StepTargetCard } from '@/components/Workflow/StepTargetCard';
import { useStepForm } from '@/hooks/Workflow/use-step-form';
import { shownErrorKeys } from '@/lib/Workflow/stepForm';
import type { WorkflowAdminFlowShowProps, WorkflowStepSettings } from '@/types';

/**
 * ステップの設定のカードと「保存」(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節)。持つ設定のカードだけを出す
 * (申請 = カード 1、承認・横配置 = 1〜3、縦配置 = 1〜4)。保存は全カードを 1 回で送り、送っている間は押せない。
 * 呼び手は、サーバーの設定が替わったら key を替えて作り直す(入力をサーバーの値から始める)。
 */
export function StepSettingsForm({
    flowCode,
    version,
    step,
    options,
}: {
    flowCode: string;
    version: number;
    step: WorkflowStepSettings;
    options: WorkflowAdminFlowShowProps['options'];
}) {
    const form = useStepForm(flowCode, version, step);

    return (
        <form noValidate onSubmit={form.submit} className="flex flex-col gap-4">
            <UnshownErrors
                errors={form.errors}
                shownKeys={shownErrorKeys(Object.keys(form.errors))}
            />
            {step.target !== null && (
                <StepTargetCard
                    kind={step.kind}
                    form={form}
                    options={options}
                />
            )}
            {step.preventsApplicantApproval !== null && (
                <StepPreventsCard kind={step.kind} form={form} />
            )}
            {step.sendBack !== null && (
                <StepSendBackCard
                    candidates={step.sendBack.candidates}
                    form={form}
                />
            )}
            {step.decision !== null && <StepDecisionCard form={form} />}
            <div className="flex justify-end">
                <Button type="submit" disabled={form.isProcessing}>
                    保存
                </Button>
            </div>
        </form>
    );
}
