import type { ReactNode } from 'react';
import { UnshownErrors } from '@/components/FormBlock';
import { ScrollWithActionBar } from '@/components/ScrollWithActionBar';
import { Button } from '@/components/ui/button';
import { NodeAttachmentCard } from '@/components/Workflow/NodeAttachmentCard';
import { StepConfirmersCard } from '@/components/Workflow/StepConfirmersCard';
import { StepDeadlineCard } from '@/components/Workflow/StepDeadlineCard';
import { StepDecisionCard } from '@/components/Workflow/StepDecisionCard';
import { StepPreventsCard } from '@/components/Workflow/StepPreventsCard';
import { StepSendBackCard } from '@/components/Workflow/StepSendBackCard';
import { StepTargetCard } from '@/components/Workflow/StepTargetCard';
import { useStepForm } from '@/hooks/Workflow/use-step-form';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { cardOrderOf } from '@/lib/Workflow/stepForm';
import type { NodeCard } from '@/lib/Workflow/stepForm';
import { shownErrorKeys } from '@/lib/Workflow/stepFormErrors';
import { stepFieldsOf } from '@/lib/Workflow/stepFields';
import type { StepFields } from '@/lib/Workflow/stepFields';
import type { WorkflowAdminFlowShowProps, WorkflowStepSettings } from '@/types';

/** フォームの id(欄の下端の帯の「保存」を form 属性で結ぶ) */
const FORM_ID = 'step-settings-form';

/** カード 1 枚を描くのに使う値(number = 並びの順) */
type CardProps = {
    number: number;
    step: WorkflowStepSettings;
    fields: StepFields;
    form: StepFormApi;
    options: WorkflowAdminFlowShowProps['options'];
};

/** カードの種類 → 描き方(出すかどうかと番号は cardOrderOf の並びが決める) */
const CARDS: Record<NodeCard, (props: CardProps) => ReactNode> = {
    target: ({ number, step, fields, form, options }) => (
        <StepTargetCard
            number={number}
            kind={step.kind}
            fields={fields.target}
            form={form}
            options={options}
        />
    ),
    prevents: ({ number, step, form }) => (
        <StepPreventsCard number={number} kind={step.kind} form={form} />
    ),
    sendBack: ({ number, step, form }) => (
        <StepSendBackCard
            number={number}
            candidates={step.sendBack?.candidates ?? []}
            form={form}
        />
    ),
    decision: ({ number, fields, form }) => (
        <StepDecisionCard
            number={number}
            fields={fields.decision}
            form={form}
        />
    ),
    deadline: ({ number, form }) => (
        <StepDeadlineCard number={number} form={form} />
    ),
    confirmers: ({ number, form }) => (
        <StepConfirmersCard number={number} form={form} />
    ),
    attachment: ({ number, form }) => (
        <NodeAttachmentCard number={number} form={form} />
    ),
};

/**
 * ステップの設定のカードと「保存」(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節、
 * 設計/ワークフロー/申請の周辺/画面.md の 4 節、設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節)。
 * 出すカードと番号は cardOrderOf の 1 つの並びで決める(番号 = 並びの順)。
 * カード 1・合議のカードの欄は stepFieldsOf(3.3 節のデシジョン表)で決める。保存は全カードを 1 回で送り、送っている間は押せない。
 * 欄の見出しと要約(header)とカードは欄の中でスクロールし、「保存」は欄の下端の帯(設計/画面の意匠/README.md の方針 14。帯はフォームの外に
 * あり、ボタンを form 属性でフォームに結ぶ)。呼び手は、サーバーの設定が替わったら key を替えて作り直す(入力をサーバーの値から始める)。
 */
export function StepSettingsForm({
    flowCode,
    version,
    step,
    options,
    header,
}: {
    flowCode: string;
    version: number;
    step: WorkflowStepSettings;
    options: WorkflowAdminFlowShowProps['options'];
    header: ReactNode;
}) {
    const form = useStepForm(flowCode, version, step);
    const { targetKind, decisionRule } = form.values;
    const fields = stepFieldsOf(step.kind, targetKind, decisionRule);

    return (
        <ScrollWithActionBar bar={<SaveButton disabled={form.isProcessing} />}>
            {header}
            <form
                id={FORM_ID}
                noValidate
                onSubmit={form.submit}
                className="flex flex-col gap-4"
            >
                <UnshownErrors
                    errors={form.errors}
                    shownKeys={shownErrorKeys(Object.keys(form.errors), fields)}
                />
                {cardOrderOf(step).map((card, index) => (
                    <CardOf
                        key={card}
                        card={card}
                        number={index + 1}
                        step={step}
                        fields={fields}
                        form={form}
                        options={options}
                    />
                ))}
            </form>
        </ScrollWithActionBar>
    );
}

/**
 * 帯の「保存」(フォームの外に置き、form 属性でフォームに結ぶ)。
 */
function SaveButton({ disabled }: { disabled: boolean }) {
    return (
        <Button type="submit" form={FORM_ID} disabled={disabled}>
            保存
        </Button>
    );
}

/**
 * 並びの 1 枚のカード。
 */
function CardOf({ card, ...props }: CardProps & { card: NodeCard }) {
    return CARDS[card](props);
}
