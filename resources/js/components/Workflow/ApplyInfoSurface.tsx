import { CountedTextInput } from '@/components/CountedTextInput';
import { DateInput } from '@/components/DateInput';
import { FieldHelp, FormBlock, invalidProps } from '@/components/FormBlock';
import { FormSurface } from '@/components/FormLayout';
import { Badge } from '@/components/ui/badge';
import { EMPTY_VALUE } from '@/lib/recordValues';
import type { WorkflowApplyProps } from '@/types';

const NAME_ID = 'workflow-case-name';
const BASE_DATE_ID = 'workflow-case-base-date';
const DEPARTMENT_ID = 'workflow-case-department';

/**
 * 申請の「申請情報」の面(フロー・申請番号・申請基準日・担当組織・申請名と、申請できない理由)。
 * 再申請(mode = reapply)は申請番号と固定の申請基準日(baseDate)を表で示し、申請基準日の入力欄(値 = baseDateInput)を出さない。
 */
export function ApplyInfoSurface({
    mode,
    flowName,
    caseNumber,
    baseDate,
    baseDateInput,
    baseDateRange,
    departmentName,
    nameMaxLength,
    name,
    defaultName,
    blocker,
    errors,
    onChangeBaseDate,
    onChangeName,
}: {
    mode: WorkflowApplyProps['mode'];
    flowName: string;
    caseNumber: string | null | undefined;
    baseDate: string;
    baseDateInput: string;
    baseDateRange: WorkflowApplyProps['baseDateRange'];
    departmentName: string | null;
    nameMaxLength: number;
    name: string;
    defaultName: string;
    blocker: string | null;
    errors: Record<string, string | undefined>;
    onChangeBaseDate: (value: string) => void;
    onChangeName: (value: string) => void;
}) {
    return (
        <FormSurface aria-labelledby="apply-info-heading">
            <h2 id="apply-info-heading" className="text-[15px] font-semibold">
                申請情報
            </h2>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                <div className="flex flex-col gap-1">
                    <dt className="text-xs text-muted-foreground">フロー</dt>
                    <dd>{flowName}</dd>
                </div>
                {mode === 'reapply' && (
                    <div className="flex flex-col gap-1">
                        <dt className="text-xs text-muted-foreground">
                            申請番号
                        </dt>
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
            {mode !== 'reapply' && (
                <FormBlock
                    id={BASE_DATE_ID}
                    label="申請基準日"
                    labelAddon={<Badge variant="secondary">必須</Badge>}
                    help="既定値は今日の日付です。フローの版と処理対象者は、この日付で決定されます。"
                    error={errors.base_date}
                >
                    <DateInput
                        id={BASE_DATE_ID}
                        type="date"
                        className="w-48"
                        value={baseDateInput}
                        min={baseDateRange.min ?? undefined}
                        max={baseDateRange.max ?? undefined}
                        onChange={(event) =>
                            onChangeBaseDate(event.target.value)
                        }
                        {...invalidProps(BASE_DATE_ID, errors.base_date, true)}
                    />
                </FormBlock>
            )}
            <FormBlock
                id={NAME_ID}
                label="申請名"
                help="空のまま申請すると、欄の中の案内の申請名が設定されます。"
                error={errors.name}
            >
                <CountedTextInput
                    id={NAME_ID}
                    value={name}
                    max={nameMaxLength}
                    invalid={errors.name !== undefined}
                    hasHelp
                    placeholder={defaultName}
                    onChange={(event) => onChangeName(event.target.value)}
                />
            </FormBlock>
            {blocker !== null && (
                <p className="text-sm text-destructive">{blocker}</p>
            )}
        </FormSurface>
    );
}
