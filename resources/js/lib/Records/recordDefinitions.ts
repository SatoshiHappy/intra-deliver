import { ORDERED_TYPES } from '@/lib/recordRules';
import { formatDate, formatDatetime } from '@/lib/recordValues';
import type {
    RecordComparisonOperator,
    RecordConditionExpression,
    RecordConditionOperator,
    RecordFieldDefinition,
    RecordFieldType,
    RecordModuleDefinition,
    RecordRuleDefinition,
    RecordRuleKind,
} from '@/types';

/**
 * 管理の画面の定義の語と可否(docs の 設計/業務データの入れ物/項目と規則/README.md 1 節・設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8 節、画面/管理の画面.md 3.3・3.4)。
 */

/** 型の画面の名前(1 節の表の順。文字列は 1 行か複数行かで分けるので fieldTypeLabel で出す) */
const FIELD_TYPE_LABELS: Record<RecordFieldType, string> = {
    string: '文字列',
    integer: '整数',
    decimal: '小数',
    date: '日付',
    datetime: '日時',
    boolean: '真偽',
    choice: '選択',
    user: 'ユーザー',
    department: '組織',
    user_or_department: 'ユーザーと組織',
    record: '他モジュール参照',
    details: '明細',
};

/** 複数(is_multiple)を持てる型(1 節の表の「複数」の列 = ユーザー・組織・ユーザーと組織) */
const MULTIPLE_TYPES: RecordFieldType[] = [
    'user',
    'department',
    'user_or_department',
];

/**
 * 複数(is_multiple)を持てる型か。
 */
export function canBeMultiple(type: RecordFieldType | ''): boolean {
    return type !== '' && MULTIPLE_TYPES.includes(type);
}

/** 関係者の印(grants_row_access)を持てる型(ユーザー・組織・ユーザーと組織。1 つと複数 = 画面/管理の画面.md 3.4) */
const ROW_ACCESS_TYPES: RecordFieldType[] = [
    'user',
    'department',
    'user_or_department',
];

/**
 * 関係者の印を持てる型か。
 */
export function canGrantRowAccess(type: RecordFieldType | ''): boolean {
    return type !== '' && ROW_ACCESS_TYPES.includes(type);
}

/**
 * 型の画面の名前(1 節: 文字列は is_multiline で「文字列(1 行)」「文字列(複数行)」、ユーザー・組織・ユーザーと組織は is_multiple で
 * 「ユーザー(複数)」「組織(複数)」「ユーザーと組織(複数)」)。項目の表・編集の固定の表示・型の一覧で使う。
 */
export function fieldTypeLabel(field: {
    type: RecordFieldType;
    isMultiline: boolean;
    isMultiple: boolean;
}): string {
    if (field.type === 'string') {
        return field.isMultiline ? '文字列(複数行)' : '文字列(1 行)';
    }

    const label = FIELD_TYPE_LABELS[field.type];

    return field.isMultiple ? `${label}(複数)` : label;
}

/** 項目の追加の型の一覧の 1 つ(value = 選択の値。送るのは type と isMultiline・isMultiple = 画面/管理の画面.md 3.4) */
export type FieldTypeChoice = {
    value: string;
    type: RecordFieldType;
    isMultiline: boolean;
    isMultiple: boolean;
};

/**
 * 型の一覧(画面/管理の画面.md 3.4: 先頭に「文字列(1 行)」「文字列(複数行)」を別の選択肢で置き、ほかの型は 1 節の表の順。
 * 「ユーザー」「組織」「ユーザーと組織」のそれぞれ直後に「ユーザー(複数)」「組織(複数)」「ユーザーと組織(複数)」)。
 */
export const FIELD_TYPE_CHOICES: FieldTypeChoice[] = [
    { value: 'string', type: 'string', isMultiline: false, isMultiple: false },
    {
        value: 'string_multiline',
        type: 'string',
        isMultiline: true,
        isMultiple: false,
    },
    ...(Object.keys(FIELD_TYPE_LABELS) as RecordFieldType[])
        .filter((type) => type !== 'string')
        .flatMap((type) => {
            const single = { value: type, type, isMultiline: false };

            return canBeMultiple(type)
                ? [
                      { ...single, isMultiple: false },
                      {
                          ...single,
                          value: `${type}_multiple`,
                          isMultiple: true,
                      },
                  ]
                : [{ ...single, isMultiple: false }];
        }),
];

/**
 * 欄の値に合う型の一覧の選択肢(型・1 行か複数行か・1 つか複数かが同じもの。型を選んでいなければ undefined)。
 */
export function typeChoiceOf(
    data: {
        type: RecordFieldType | '';
        isMultiline: boolean;
        isMultiple: boolean;
    },
    choices: FieldTypeChoice[],
): FieldTypeChoice | undefined {
    return choices.find(
        (choice) =>
            choice.type === data.type &&
            choice.isMultiline === data.isMultiline &&
            choice.isMultiple === data.isMultiple,
    );
}

/** 規則の種別の画面の名前(8.1) */
export const RULE_KIND_LABELS: Record<RecordRuleKind, string> = {
    required_when: '条件つき必須',
    compare: '相関チェック',
};

/** 相関チェックの比べ方の語と記号(8.1) */
export const COMPARISON_OPERATORS: Record<
    RecordComparisonOperator,
    { label: string; symbol: string }
> = {
    lt: { label: 'より小さい', symbol: '<' },
    le: { label: '以下', symbol: '≦' },
    eq: { label: '等しい', symbol: '=' },
    ne: { label: '等しくない', symbol: '≠' },
    ge: { label: '以上', symbol: '≧' },
    gt: { label: 'より大きい', symbol: '>' },
};

/** 条件の演算子の語(8.2) */
export const CONDITION_OPERATOR_LABELS: Record<
    RecordConditionOperator,
    string
> = {
    eq: '等しい',
    ne: '等しくない',
    lt: 'より小さい',
    le: '以下',
    ge: '以上',
    gt: 'より大きい',
    in: 'どれかに等しい',
    empty: '空',
    not_empty: '空でない',
};

/**
 * 値を持つ演算子か(empty・not_empty は値を持たない)。
 */
export function operatorHasValue(op: RecordConditionOperator): boolean {
    return op !== 'empty' && op !== 'not_empty';
}

/**
 * 規則の対象にできる項目か(条件つき必須 = 真偽のほか、相関チェック = 整数・小数・日付・日時。8.1)。
 */
export function isRuleTarget(
    kind: RecordRuleKind,
    field: RecordFieldDefinition,
): boolean {
    return kind === 'required_when'
        ? field.type !== 'boolean'
        : ORDERED_TYPES.includes(field.type);
}

/**
 * 条件の値の表示(選択は名称、真偽は「はい」「いいえ」、日付・日時は 1.3 の表示の形)。
 */
function conditionValueText(
    field: RecordFieldDefinition | undefined,
    value: RecordConditionExpression['value'],
): string {
    const one = (item: string | number | boolean) => {
        if (typeof item === 'boolean') {
            return item ? 'はい' : 'いいえ';
        }

        if (field?.type === 'choice') {
            return (
                field.options.find((option) => option.code === item)?.name ??
                String(item)
            );
        }

        if (field?.type === 'date') {
            return formatDate(String(item));
        }

        return field?.type === 'datetime'
            ? formatDatetime(String(item))
            : String(item);
    };

    if (value === undefined) {
        return '';
    }

    return Array.isArray(value) ? value.map(one).join('・') : one(value);
}

/**
 * 条件の式 1 つの文(例「状態 が 完了」)。
 */
function expressionText(
    module: RecordModuleDefinition,
    expression: RecordConditionExpression,
): string {
    const field = module.fields.find((item) => item.code === expression.field);
    const name = field?.name ?? expression.field;
    const value = conditionValueText(field, expression.value);

    switch (expression.op) {
        case 'eq':
            return `${name} が ${value}`;
        case 'ne':
            return `${name} が ${value} 以外`;
        case 'in':
            return `${name} が ${value} のどれか`;
        case 'empty':
            return `${name} が空`;
        case 'not_empty':
            return `${name} が空でない`;
        default:
            return `${name} ${COMPARISON_OPERATORS[expression.op].symbol} ${value}`;
    }
}

/**
 * 規則の中身の人が読める文(画面/管理の画面.md 3.3。例「状態 が 完了 のとき、解決内容 は必須」「終了日 ≧ 開始日」)。
 */
export function ruleSummary(
    module: RecordModuleDefinition,
    rule: RecordRuleDefinition,
): string {
    const nameOf = (code: string) =>
        module.fields.find((field) => field.code === code)?.name ?? code;

    if (rule.kind === 'compare') {
        return `${nameOf(rule.target)} ${COMPARISON_OPERATORS[rule.operator].symbol} ${nameOf(rule.compared)}`;
    }

    const conditions = rule.condition.all
        .map((expression) => expressionText(module, expression))
        .join('、');

    return `${conditions} のとき、${nameOf(rule.target)} は必須`;
}
