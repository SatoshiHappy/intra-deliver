import { StepSettingsForm } from '@/components/Workflow/StepSettingsForm';
import { stepHeadingText } from '@/lib/Workflow/flowDefinitionTexts';
import { stepSignature } from '@/lib/Workflow/stepForm';
import type { WorkflowAdminFlowShowProps, WorkflowStepSettings } from '@/types';

/**
 * 図で選んだステップの欄(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節): 見出し(ステップの名前と種類の語)・
 * 要約の 1 文(設定から作った文)・設定のカード。分岐・同期は設定を持たないので、カードを出さず要約だけ。
 */
export function StepSettingsPane({
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
    const hasCards = step.target !== null;

    return (
        <section
            aria-labelledby="step-settings-heading"
            className="flex min-w-0 flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">
                    図で選んだステップ
                </span>
                <h2
                    id="step-settings-heading"
                    className="text-[15px] font-semibold wrap-anywhere"
                >
                    {stepHeadingText(step.name, step.kind)}
                </h2>
            </div>
            <p className="text-sm wrap-anywhere">{step.summary}</p>
            {hasCards && (
                <StepSettingsForm
                    key={`${version}|${stepSignature(step)}`}
                    flowCode={flowCode}
                    version={version}
                    step={step}
                    options={options}
                />
            )}
        </section>
    );
}
