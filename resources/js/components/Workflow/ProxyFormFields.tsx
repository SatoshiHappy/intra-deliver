import { DateInput } from '@/components/DateInput';
import { FormBlock, invalidProps } from '@/components/FormBlock';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ALL_FLOWS_LABEL,
    PROXY_KIND_LABELS,
} from '@/lib/Workflow/proxyDisplay';
import { withScope } from '@/lib/Workflow/proxyForm';
import type { ProxyForm, ProxyScope } from '@/lib/Workflow/proxyForm';
import type { WorkflowProxyFlow, WorkflowProxyKind } from '@/types';

/** 欄の id */
const KIND_ID = 'proxy-kind';
const SCOPE_ID = 'proxy-scope';
const FLOW_ID = 'proxy-flow';
const START_ID = 'proxy-start-date';
const END_ID = 'proxy-end-date';

/** 必須の札(業務のフォームは「必須」の札のまま = 業務の画面.md 2.2) */
const REQUIRED = <Badge variant="secondary">必須</Badge>;

type Choice<T extends string> = { value: T; label: string };

const KIND_CHOICES: Choice<WorkflowProxyKind>[] = [
    { value: 'approve', label: PROXY_KIND_LABELS.approve },
    { value: 'apply', label: PROXY_KIND_LABELS.apply },
];

const SCOPE_CHOICES: Choice<ProxyScope>[] = [
    { value: 'all', label: ALL_FLOWS_LABEL },
    { value: 'flow', label: 'フローを指定' },
];

type Props = {
    data: ProxyForm;
    errors: Record<string, string | undefined>;
    flows: WorkflowProxyFlow[];
    setData: (update: (current: ProxyForm) => ProxyForm) => void;
};

/**
 * 2 つから 1 つを選ぶ切替(ラジオ。欄の名前は FormBlock のラベル。説明と誤りの文言は FormBlock の `-help`・`-error` に結ぶ)。
 */
function ChoiceRadios<T extends string>({
    id,
    value,
    choices,
    error,
    isDisabled = () => false,
    onChange,
}: {
    id: string;
    value: T;
    choices: Choice<T>[];
    error: string | undefined;
    isDisabled?: (choice: T) => boolean;
    onChange: (value: T) => void;
}) {
    return (
        <RadioGroup
            value={value}
            onValueChange={(next) => {
                const choice = choices.find((one) => one.value === next);

                if (choice !== undefined) {
                    onChange(choice.value);
                }
            }}
            aria-labelledby={`${id}-label`}
            {...invalidProps(id, error, true)}
            className="flex gap-6"
        >
            {choices.map((choice) => (
                <div key={choice.value} className="flex items-center gap-2">
                    <RadioGroupItem
                        id={`${id}-${choice.value}`}
                        value={choice.value}
                        disabled={isDisabled(choice.value)}
                    />
                    <Label htmlFor={`${id}-${choice.value}`}>
                        {choice.label}
                    </Label>
                </div>
            ))}
        </RadioGroup>
    );
}

/**
 * 「対象」の欄(すべてのフロー / フローを指定の切替と、指定のときのフローの選択)。フローが 1 つも無ければ指定を選べない。
 */
function ScopeField({ data, errors, flows, setData }: Props) {
    const error = errors.workflow_flow_code;

    return (
        <FormBlock
            id={SCOPE_ID}
            label="対象"
            labelAddon={REQUIRED}
            help="フローを指定する場合は、フローごとに 1 件ずつ追加します。"
            error={error}
        >
            <ChoiceRadios
                id={SCOPE_ID}
                value={data.scope}
                choices={SCOPE_CHOICES}
                error={error}
                isDisabled={(choice) => choice === 'flow' && flows.length === 0}
                onChange={(scope) =>
                    setData((current) => withScope(current, scope, flows))
                }
            />
            {data.scope === 'flow' && (
                <Select
                    value={data.flow_code}
                    onValueChange={(code) =>
                        setData((current) => ({ ...current, flow_code: code }))
                    }
                >
                    <SelectTrigger
                        id={FLOW_ID}
                        aria-label="対象のフロー"
                        className="w-full"
                        {...invalidProps(SCOPE_ID, error, true)}
                    >
                        <SelectValue placeholder="選択してください" />
                    </SelectTrigger>
                    <SelectContent>
                        {flows.map((flow) => (
                            <SelectItem key={flow.code} value={flow.code}>
                                {flow.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
        </FormBlock>
    );
}

/**
 * 開始日と終了日の欄(終了日は最後の有効な日。空 = 終了日なし)。
 */
function PeriodFields({ data, errors, setData }: Props) {
    return (
        <>
            <FormBlock
                id={START_ID}
                label="開始日"
                labelAddon={REQUIRED}
                error={errors.start_date}
            >
                <DateInput
                    type="date"
                    id={START_ID}
                    value={data.start_date}
                    onChange={(event) =>
                        setData((current) => ({
                            ...current,
                            start_date: event.target.value,
                        }))
                    }
                    {...invalidProps(START_ID, errors.start_date)}
                />
            </FormBlock>
            <FormBlock
                id={END_ID}
                label="終了日"
                labelAddon={<Badge variant="outline">任意</Badge>}
                help="終了日まで有効です。空欄の場合は、終了日なしで有効です。"
                error={errors.end_date}
            >
                <DateInput
                    type="date"
                    id={END_ID}
                    value={data.end_date}
                    onChange={(event) =>
                        setData((current) => ({
                            ...current,
                            end_date: event.target.value,
                        }))
                    }
                    {...invalidProps(END_ID, errors.end_date, true)}
                />
            </FormBlock>
        </>
    );
}

/**
 * 種類・対象・開始日・終了日の欄(代理先の次。管理と通知の画面.md の 7.3 節)。
 */
export function ProxyFormFields(props: Props) {
    const { data, errors, setData } = props;

    return (
        <>
            <FormBlock
                id={KIND_ID}
                label="種類"
                labelAddon={REQUIRED}
                help="承認は、承認・否認・差戻し・保留・担当変更などを代わりに処理します。申請は、申請・再申請・取止めなどを代わりに処理します。"
                error={errors.kind}
            >
                <ChoiceRadios
                    id={KIND_ID}
                    value={data.kind}
                    choices={KIND_CHOICES}
                    error={errors.kind}
                    onChange={(kind) =>
                        setData((current) => ({ ...current, kind }))
                    }
                />
            </FormBlock>
            <ScopeField {...props} />
            <PeriodFields {...props} />
        </>
    );
}
