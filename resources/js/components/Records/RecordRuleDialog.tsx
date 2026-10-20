import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, RequiredMark } from '@/components/FormBlock';
import { RecordFieldInput } from '@/components/RecordFieldInput';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    COMPARISON_OPERATORS,
    CONDITION_OPERATOR_LABELS,
    isRuleTarget,
    operatorHasValue,
    RULE_KIND_LABELS,
} from '@/lib/Records/recordDefinitions';
import type { RecordFieldDraft } from '@/lib/recordForm';
import { currentDraft, payloadValue } from '@/lib/recordForm';
import { clearRowErrors } from '@/lib/formErrors';
import { conditionOperatorsOf } from '@/lib/recordRules';
import { store, update } from '@/routes/records/admin/rules';
import type {
    RecordComparisonOperator,
    RecordConditionExpression,
    RecordConditionOperator,
    RecordFieldDefinition,
    RecordModuleDefinition,
    RecordRuleDefinition,
    RecordRuleKind,
} from '@/types';

/** 条件の式 1 つの欄(in は選んだ選択肢のコード) */
type ExpressionDraft = {
    key: number;
    field: string;
    op: RecordConditionOperator | '';
    value: RecordFieldDraft;
    codes: string[];
};

type RuleDraft = {
    kind: RecordRuleKind;
    target: string;
    expressions: ExpressionDraft[];
    operator: RecordComparisonOperator | '';
    compared: string;
    message: string;
};

type Props = {
    module: RecordModuleDefinition;
    /** 編集する規則(null = 追加) */
    rule: RecordRuleDefinition | null;
    /** Dialog を開くボタン */
    trigger: ReactNode;
    /** 文言の文字数の上限(サーバーの値を props で受ける) */
    messageMaxLength: number;
};

/** 条件の式の数の上限(項目と規則/規則と業務処理/README.md 8.2) */
const MAX_EXPRESSIONS = 10;

/** 新しい式の key の連番(画面の中だけ) */
let nextExpressionKey = 0;

/**
 * 条件に使える項目(明細を除き、使える演算子が 1 つ以上ある項目 = 8.2 の表)。
 */
function conditionFields(module: RecordModuleDefinition) {
    return module.fields.filter(
        (field) => conditionOperatorsOf(field.type).length > 0,
    );
}

/**
 * 値の欄の初期値(真偽は偽、参照は無し、ほかは空)。
 */
function emptyValue(
    field: RecordFieldDefinition | undefined,
): RecordFieldDraft {
    return field?.type === 'boolean' ? false : '';
}

/**
 * 式の欄(規則の式から。新しい式は項目も演算子も未選択)。
 */
function expressionDraft(
    module: RecordModuleDefinition,
    expression: RecordConditionExpression | null,
): ExpressionDraft {
    nextExpressionKey += 1;
    const field = module.fields.find((item) => item.code === expression?.field);
    const value = expression?.value;

    return {
        key: nextExpressionKey,
        field: expression?.field ?? '',
        op: expression?.op ?? '',
        value:
            field === undefined || value === undefined || Array.isArray(value)
                ? emptyValue(field)
                : currentDraft(field, value),
        codes: Array.isArray(value) ? value : [],
    };
}

/**
 * Dialog の初期値(追加 = 条件つき必須・式 1 つ、編集 = 今の規則)。
 */
function initialDraft(
    module: RecordModuleDefinition,
    rule: RecordRuleDefinition | null,
): RuleDraft {
    if (rule === null) {
        return {
            kind: 'required_when',
            target: '',
            expressions: [expressionDraft(module, null)],
            operator: '',
            compared: '',
            message: '',
        };
    }

    return {
        kind: rule.kind,
        target: rule.target,
        expressions:
            rule.kind === 'required_when'
                ? rule.condition.all.map((expression) =>
                      expressionDraft(module, expression),
                  )
                : [],
        operator: rule.kind === 'compare' ? rule.operator : '',
        compared: rule.kind === 'compare' ? rule.compared : '',
        message: rule.message ?? '',
    };
}

/**
 * 送る形(業務処理の入力 = { kind, target, condition, operator, compared, message }。種別に関係しないキーは送らない。空は null)。
 */
function toPayload(module: RecordModuleDefinition, draft: RuleDraft) {
    const common = {
        kind: draft.kind,
        target: draft.target === '' ? null : draft.target,
        message: draft.message === '' ? null : draft.message,
    };

    if (draft.kind === 'compare') {
        return {
            ...common,
            operator: draft.operator === '' ? null : draft.operator,
            compared: draft.compared === '' ? null : draft.compared,
        };
    }

    return {
        ...common,
        condition: {
            all: draft.expressions.map((expression) => {
                const field = module.fields.find(
                    (item) => item.code === expression.field,
                );
                const base = {
                    field: expression.field === '' ? null : expression.field,
                    op: expression.op === '' ? null : expression.op,
                };

                if (expression.op === '' || !operatorHasValue(expression.op)) {
                    return base;
                }

                return {
                    ...base,
                    value:
                        expression.op === 'in'
                            ? expression.codes
                            : field === undefined
                              ? null
                              : payloadValue(field, expression.value),
                };
            }),
        },
    };
}

/**
 * Radix の Select が空文字で知らせる値の変更を捨てる。値と選択肢を同じ描画で変えると(式の項目を替えて演算子を最初のものにするとき)、
 * Select の隠れた native の select にまだ新しい選択肢が無く、値を '' にした change を出して onValueChange('') が呼ばれ、
 * 選んだ値が消える(Radix の SelectBubbleInput)。この Dialog の Select は空の値を選ばせないので、'' は捨ててよい。
 */
function ignoreEmpty(onChange: (value: string) => void) {
    return (value: string) => {
        if (value !== '') {
            onChange(value);
        }
    };
}

/**
 * 項目を選ぶ Select(コードで選ぶ)。
 */
function FieldSelect({
    id,
    label,
    fields,
    value,
    onChange,
    invalid,
}: {
    id: string;
    label?: string;
    fields: RecordFieldDefinition[];
    value: string;
    onChange: (code: string) => void;
    invalid: boolean;
}) {
    return (
        <Select value={value} onValueChange={ignoreEmpty(onChange)}>
            <SelectTrigger
                id={id}
                aria-label={label}
                aria-invalid={invalid || undefined}
                className="w-full"
            >
                <SelectValue placeholder="選択してください" />
            </SelectTrigger>
            <SelectContent>
                {fields.map((field) => (
                    <SelectItem key={field.code} value={field.code}>
                        {field.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * 条件の式 1 行(項目・演算子・値・行の削除)。
 */
function ExpressionRow({
    module,
    index,
    expression,
    errors,
    onChange,
    onRemove,
}: {
    module: RecordModuleDefinition;
    index: number;
    expression: ExpressionDraft;
    errors: Record<string, string | undefined>;
    onChange: (expression: ExpressionDraft) => void;
    onRemove: (() => void) | null;
}) {
    const field = module.fields.find((item) => item.code === expression.field);
    const operators =
        field === undefined ? [] : conditionOperatorsOf(field.type);
    const key = `condition.all.${index}`;
    const errorIdOf = (part: string) =>
        `rule-condition-${expression.key}-${part}-error`;
    const rowErrors = (['field', 'op', 'value'] as const).flatMap((part) => {
        const message = errors[`${key}.${part}`];

        return message === undefined ? [] : [{ part, message }];
    });
    const position = `(${index + 1} 行目)`;

    return (
        <div className="flex flex-col gap-1">
            <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.4fr)_auto] items-start gap-2">
                <FieldSelect
                    id={`rule-condition-${expression.key}-field`}
                    label={`条件の項目${position}`}
                    fields={conditionFields(module)}
                    value={expression.field}
                    invalid={errors[`${key}.field`] !== undefined}
                    onChange={(code) => {
                        const next = module.fields.find(
                            (item) => item.code === code,
                        );
                        onChange({
                            ...expression,
                            field: code,
                            op:
                                next === undefined
                                    ? ''
                                    : conditionOperatorsOf(next.type)[0],
                            value: emptyValue(next),
                            codes: [],
                        });
                    }}
                />
                <Select
                    value={expression.op}
                    onValueChange={ignoreEmpty((op) =>
                        onChange({
                            ...expression,
                            op: op as RecordConditionOperator,
                        }),
                    )}
                >
                    <SelectTrigger
                        aria-label={`条件の演算子${position}`}
                        aria-invalid={
                            errors[`${key}.op`] !== undefined || undefined
                        }
                        className="w-full"
                    >
                        <SelectValue placeholder="演算子" />
                    </SelectTrigger>
                    <SelectContent>
                        {operators.map((op) => (
                            <SelectItem key={op} value={op}>
                                {CONDITION_OPERATOR_LABELS[op]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <div>
                    {field !== undefined && expression.op === 'in' && (
                        <fieldset
                            aria-label={`条件の値${position}`}
                            aria-invalid={
                                errors[`${key}.value`] !== undefined ||
                                undefined
                            }
                            className="flex flex-wrap gap-x-4 gap-y-2 pt-2"
                        >
                            {field.options.map((option) => {
                                const id = `rule-condition-${expression.key}-in-${option.code}`;

                                return (
                                    <div
                                        key={option.code}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={id}
                                            checked={expression.codes.includes(
                                                option.code,
                                            )}
                                            onCheckedChange={(checked) =>
                                                onChange({
                                                    ...expression,
                                                    codes:
                                                        checked === true
                                                            ? [
                                                                  ...expression.codes,
                                                                  option.code,
                                                              ]
                                                            : expression.codes.filter(
                                                                  (code) =>
                                                                      code !==
                                                                      option.code,
                                                              ),
                                                })
                                            }
                                        />
                                        <Label htmlFor={id}>
                                            {option.name}
                                        </Label>
                                    </div>
                                );
                            })}
                        </fieldset>
                    )}
                    {field !== undefined &&
                        expression.op !== '' &&
                        expression.op !== 'in' &&
                        operatorHasValue(expression.op) && (
                            <RecordFieldInput
                                id={`rule-condition-${expression.key}-value`}
                                field={{ ...field, isRequired: true }}
                                recordId={null}
                                draft={expression.value}
                                onChange={(value) =>
                                    onChange({ ...expression, value })
                                }
                                isRequiredNow
                                invalid={errors[`${key}.value`] !== undefined}
                                ariaLabel={`条件の値${position}`}
                            />
                        )}
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`条件を削除${position}`}
                    disabled={onRemove === null}
                    onClick={() => onRemove?.()}
                >
                    <Trash2 />
                </Button>
            </div>
            {rowErrors.map(({ part, message }) => (
                <p
                    key={part}
                    id={errorIdOf(part)}
                    className="text-sm text-destructive"
                >
                    {message}
                </p>
            ))}
        </div>
    );
}

/**
 * 規則の Dialog(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3。追加・編集で同じ)。
 * 条件の行の値の欄は見える名前を持たないので、演算子による必須は条件の群の印に含める。
 */
export function RecordRuleDialog({
    module,
    rule,
    trigger,
    messageMaxLength,
}: Props) {
    const [open, setOpen] = useState(false);
    // 初期値は 1 回だけ作る(useForm は関数を描画ごとに呼ぶので、useState で持つ)
    const [initial] = useState(() => initialDraft(module, rule));
    const form = useForm<RuleDraft>(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const draft = form.data;
    const targets = module.fields.filter((field) =>
        isRuleTarget(draft.kind, field),
    );
    const targetField = module.fields.find(
        (field) => field.code === draft.target,
    );
    const compared = module.fields.filter(
        (field) =>
            field.type === targetField?.type && field.code !== draft.target,
    );
    const idPrefix = rule === null ? 'rule-new' : `rule-${rule.id}`;
    const setExpressions = (expressions: ExpressionDraft[]) =>
        form.setData((data) => ({ ...data, expressions }));

    const changeOpen = (next: boolean) => {
        setOpen(next);

        if (next) {
            form.setData(initialDraft(module, rule));
            form.clearErrors();
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => toPayload(module, data));
        form.submit(
            rule === null
                ? store(module.code)
                : update({ module: module.code, rule: rule.id }),
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-3xl">
                <form noValidate onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {rule === null ? '規則の追加' : '規則の編集'}
                        </DialogTitle>
                        <DialogDescription>
                            文言を空にすると既定の文言が表示されます。
                        </DialogDescription>
                    </DialogHeader>
                    <FormBlock
                        id={`${idPrefix}-kind`}
                        label="種別"
                        isRequired={rule === null}
                        error={errors.kind}
                    >
                        <Select
                            value={draft.kind}
                            disabled={rule !== null}
                            onValueChange={(kind) => {
                                form.setData({
                                    ...initialDraft(module, null),
                                    kind: kind as RecordRuleKind,
                                    expressions:
                                        kind === 'required_when'
                                            ? [expressionDraft(module, null)]
                                            : [],
                                });
                                // 前の種別のサーバーのエラーを新しい欄に残さない
                                form.clearErrors();
                            }}
                        >
                            <SelectTrigger
                                id={`${idPrefix}-kind`}
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(['required_when', 'compare'] as const).map(
                                    (kind) => (
                                        <SelectItem key={kind} value={kind}>
                                            {RULE_KIND_LABELS[kind]}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </FormBlock>
                    <FormBlock
                        id={`${idPrefix}-target`}
                        label="対象の項目"
                        isRequired
                        error={errors.target}
                    >
                        <FieldSelect
                            id={`${idPrefix}-target`}
                            fields={targets}
                            value={draft.target}
                            invalid={errors.target !== undefined}
                            onChange={(target) =>
                                form.setData((data) => ({
                                    ...data,
                                    target,
                                    compared: '',
                                }))
                            }
                        />
                    </FormBlock>
                    {draft.kind === 'required_when' ? (
                        <div
                            role="group"
                            aria-label="条件"
                            className="flex flex-col gap-2"
                        >
                            <span className="flex items-center gap-2 text-sm font-medium">
                                条件(すべて満たすとき)
                                <RequiredMark />
                            </span>
                            {errors.condition !== undefined && (
                                <p className="text-sm text-destructive">
                                    {errors.condition}
                                </p>
                            )}
                            {draft.expressions.map((expression, index) => (
                                <ExpressionRow
                                    key={expression.key}
                                    module={module}
                                    index={index}
                                    expression={expression}
                                    errors={errors}
                                    onChange={(changed) =>
                                        setExpressions(
                                            draft.expressions.map(
                                                (item, position) =>
                                                    position === index
                                                        ? changed
                                                        : item,
                                            ),
                                        )
                                    }
                                    onRemove={
                                        draft.expressions.length <= 1
                                            ? null
                                            : () => {
                                                  setExpressions(
                                                      draft.expressions.filter(
                                                          (_, position) =>
                                                              position !==
                                                              index,
                                                      ),
                                                  );
                                                  // 誤りのキーは添字で行を指すので、詰まった後の行に別の行の誤りを残さない
                                                  clearRowErrors(
                                                      form,
                                                      'condition.all.',
                                                  );
                                              }
                                    }
                                />
                            ))}
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        draft.expressions.length >=
                                        MAX_EXPRESSIONS
                                    }
                                    onClick={() =>
                                        setExpressions([
                                            ...draft.expressions,
                                            expressionDraft(module, null),
                                        ])
                                    }
                                >
                                    <Plus />
                                    条件を追加
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-4">
                            <FormBlock
                                id={`${idPrefix}-operator`}
                                label="比べ方"
                                isRequired
                                error={errors.operator}
                            >
                                <Select
                                    value={draft.operator}
                                    onValueChange={(operator) =>
                                        form.setData((data) => ({
                                            ...data,
                                            operator:
                                                operator as RecordComparisonOperator,
                                        }))
                                    }
                                >
                                    <SelectTrigger
                                        id={`${idPrefix}-operator`}
                                        aria-invalid={
                                            errors.operator !== undefined ||
                                            undefined
                                        }
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="選択してください" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(
                                            Object.keys(
                                                COMPARISON_OPERATORS,
                                            ) as RecordComparisonOperator[]
                                        ).map((operator) => (
                                            <SelectItem
                                                key={operator}
                                                value={operator}
                                            >
                                                {
                                                    COMPARISON_OPERATORS[
                                                        operator
                                                    ].symbol
                                                }{' '}
                                                {
                                                    COMPARISON_OPERATORS[
                                                        operator
                                                    ].label
                                                }
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormBlock>
                            <FormBlock
                                id={`${idPrefix}-compared`}
                                label="相手の項目"
                                isRequired
                                error={errors.compared}
                            >
                                <FieldSelect
                                    id={`${idPrefix}-compared`}
                                    fields={compared}
                                    value={draft.compared}
                                    invalid={errors.compared !== undefined}
                                    onChange={(code) =>
                                        form.setData((data) => ({
                                            ...data,
                                            compared: code,
                                        }))
                                    }
                                />
                            </FormBlock>
                        </div>
                    )}
                    <FormBlock
                        id={`${idPrefix}-message`}
                        label="文言"
                        error={errors.message}
                    >
                        <CountedTextInput
                            id={`${idPrefix}-message`}
                            value={draft.message}
                            max={messageMaxLength}
                            invalid={errors.message !== undefined}
                            onChange={(event) =>
                                form.setData((data) => ({
                                    ...data,
                                    message: event.target.value,
                                }))
                            }
                        />
                    </FormBlock>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            キャンセル
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {rule === null ? '追加' : '更新'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
