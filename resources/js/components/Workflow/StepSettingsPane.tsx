import { StepSettingsForm } from '@/components/Workflow/StepSettingsForm';
import { Badge } from '@/components/ui/badge';
import { stepKindWord } from '@/lib/Workflow/flowDefinitionTexts';
import { stepSignature } from '@/lib/Workflow/stepForm';
import { cn } from '@/lib/utils';
import type { WorkflowAdminFlowShowProps, WorkflowStepSettings } from '@/types';

/** 種類のチップ(灰色の地) */
const KIND_CHIP_CLASSES = cn(
    'border-transparent bg-status-settled font-medium',
    'text-status-settled-foreground',
);

/** 要約の 1 文(緑の帯の太字) */
const SUMMARY_CLASSES = cn(
    'rounded-lg bg-secondary px-3 py-2.5 text-sm font-semibold wrap-anywhere',
    'text-secondary-foreground',
);

/**
 * 欄の見出し(ステップの名前と、横に種類のチップ。分岐・同期は名前だけ)。読み上げの名前は「部長 承認」の形。
 */
function StepPaneHeading({ step }: { step: WorkflowStepSettings }) {
    const kindWord = stepKindWord(step.kind);

    return (
        <div className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">
                図で選んだステップ
            </span>
            <h2
                id="step-settings-heading"
                className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[15px] font-semibold wrap-anywhere"
            >
                {step.name}
                {kindWord !== null && (
                    <>
                        {' '}
                        <Badge variant="outline" className={KIND_CHIP_CLASSES}>
                            {kindWord}
                        </Badge>
                    </>
                )}
            </h2>
        </div>
    );
}

/**
 * 図で選んだステップの欄(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節): 見出し(ステップの名前と、横に種類のチップ)・
 * 要約の 1 文(設定から作った文。緑の帯の太字)・設定のカード。分岐・同期は設定を持たないので、カードを出さず要約だけ。
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
    return (
        <section
            aria-labelledby="step-settings-heading"
            className="flex min-w-0 flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <StepPaneHeading step={step} />
            <p className={SUMMARY_CLASSES}>{step.summary}</p>
            {step.target !== null && (
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
