import type { InertiaFormProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { DateInput } from '@/components/DateInput';
import { FieldHelp, FormBlock, invalidProps } from '@/components/FormBlock';
import { FormSurface } from '@/components/FormLayout';
import { Badge } from '@/components/ui/badge';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import { EMPTY_VALUE } from '@/lib/recordValues';
import type { WorkflowApplyProps } from '@/types';

const NAME_ID = 'workflow-case-name';
const BASE_DATE_ID = 'workflow-case-base-date';
const DEPARTMENT_ID = 'workflow-case-department';

/**
 * 申請情報の表(フロー・申請番号・申請基準日・担当組織)。申請番号と固定の申請基準日(baseDate)は再申請だけが表で示す。
 */
function InfoTable({
    mode,
    flowName,
    caseNumber,
    baseDate,
    departmentName,
}: {
    mode: WorkflowApplyProps['mode'];
    flowName: string;
    caseNumber: string | null | undefined;
    baseDate: string;
    departmentName: string | null;
}) {
    return (
        <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm md:grid-cols-2">
            <div className="flex flex-col gap-1">
                <dt className="text-xs text-muted-foreground">フロー</dt>
                <dd>{flowName}</dd>
            </div>
            {mode === 'reapply' && (
                <div className="flex flex-col gap-1">
                    <dt className="text-xs text-muted-foreground">申請番号</dt>
                    <dd className="font-num tabular-nums">{caseNumber}</dd>
                </div>
            )}
            {mode === 'reapply' && (
                <div className="flex flex-col gap-1">
                    <dt className="text-xs text-muted-foreground">
                        申請基準日
                    </dt>
                    <dd className="font-num tabular-nums">
                        {baseDate.replaceAll('-', '/')}
                    </dd>
                </div>
            )}
            <div className="flex flex-col gap-1">
                <dt className="flex items-center gap-1 text-xs text-muted-foreground">
                    担当組織
                    <FieldHelp id={DEPARTMENT_ID} label="担当組織">
                        ヘッダーの組織の切替で変更できます。申請基準日にその所属が無い場合は主所属になります。
                    </FieldHelp>
                </dt>
                <dd {...invalidProps(DEPARTMENT_ID, undefined, true)}>
                    {departmentName ?? EMPTY_VALUE}
                </dd>
            </div>
        </dl>
    );
}

/**
 * 申請基準日の入力欄(再申請は申請基準日を変えないので呼ばない)。
 */
function BaseDateField({
    value,
    range,
    error,
    onChange,
}: {
    value: string;
    range: WorkflowApplyProps['baseDateRange'];
    error: string | undefined;
    onChange: (value: string) => void;
}) {
    return (
        <FormBlock
            id={BASE_DATE_ID}
            label="申請基準日"
            labelAddon={<Badge variant="secondary">必須</Badge>}
            help="既定値は今日の日付です。フローの版と処理担当者は、この日付で決定されます。"
            error={error}
        >
            <DateInput
                id={BASE_DATE_ID}
                type="date"
                className="w-48"
                value={value}
                min={range.min ?? undefined}
                max={range.max ?? undefined}
                onChange={(event) => onChange(event.target.value)}
                {...invalidProps(BASE_DATE_ID, error, true)}
            />
        </FormBlock>
    );
}

/**
 * 申請名の入力欄(空のまま申請すると、欄の中の案内 = placeholder の申請名が設定される)。
 */
function NameField({
    value,
    max,
    placeholder,
    error,
    onChange,
}: {
    value: string;
    max: number;
    placeholder: string;
    error: string | undefined;
    onChange: (value: string) => void;
}) {
    return (
        <FormBlock
            id={NAME_ID}
            label="申請名"
            help="空のまま申請すると、欄の中の案内の申請名が設定されます。"
            error={error}
        >
            <CountedTextInput
                id={NAME_ID}
                value={value}
                max={max}
                invalid={error !== undefined}
                hasHelp
                placeholder={placeholder}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormBlock>
    );
}

/**
 * 申請の「申請情報」の面(申請者の欄と注意の囲み = children、フロー・申請番号・申請基準日・担当組織・申請名と、申請できない理由)。
 * 再申請(mode = reapply)は申請番号と固定の申請基準日(props.baseDate)を表で示し、申請基準日の入力欄(値 = form.data.base_date)を出さない。
 */
export function ApplyInfoSurface({
    props,
    form,
    view,
    onChangeBaseDate,
    children,
}: {
    props: WorkflowApplyProps;
    form: InertiaFormProps<ApplyForm>;
    view: { defaultName: string; blocker: string | null };
    onChangeBaseDate: (value: string) => void;
    children?: ReactNode;
}) {
    const { mode, flow, case: workflowCase, next } = props;
    const { errors } = form;

    return (
        <FormSurface aria-labelledby="apply-info-heading">
            <h2 id="apply-info-heading" className="text-[15px] font-semibold">
                申請情報
            </h2>
            {children}
            <InfoTable
                mode={mode}
                flowName={flow.name}
                caseNumber={workflowCase?.number}
                baseDate={props.baseDate}
                departmentName={next.departmentName}
            />
            {mode !== 'reapply' && (
                <BaseDateField
                    value={form.data.base_date}
                    range={props.baseDateRange}
                    error={errors.base_date}
                    onChange={onChangeBaseDate}
                />
            )}
            <NameField
                value={form.data.name}
                max={props.nameMaxLength}
                placeholder={view.defaultName}
                error={errors.name}
                onChange={(value) => form.setData('name', value)}
            />
            {view.blocker !== null && (
                <p className="text-sm text-destructive">{view.blocker}</p>
            )}
        </FormSurface>
    );
}
