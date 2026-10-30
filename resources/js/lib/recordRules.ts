import type { RecordFieldDraft } from '@/lib/recordForm';
import type {
    RecordConditionExpression,
    RecordConditionOperator,
    RecordFieldDefinition,
    RecordFieldType,
    RecordModuleDefinition,
} from '@/types';

/**
 * フォームの「必須」の印の評価(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 12 節): 項目の必須と、条件つき必須の規則の条件
 * (規則と業務処理/README.md 8.2 の意味)を、入力中の値で評価する。サーバーの評価(同 9.2)が正本で、これは送る前の案内(送った後の 422 が最終の判定)。
 * 相関チェックは評価しない。小数は JavaScript の数で比べ、日時はフォームの値(Asia/Tokyo)を UTC の時刻に直して比べる。
 */

/** 大小を比べられる型(相関チェックの項目と、条件の lt・le・ge・gt) */
export const ORDERED_TYPES: RecordFieldType[] = [
    'integer',
    'decimal',
    'date',
    'datetime',
];

/**
 * 項目の型で使える条件の演算子(8.2 の表。明細は条件に使えない)。規則の作成の画面の選択肢と、フォームの評価が同じ表を使う。
 */
export function conditionOperatorsOf(
    type: RecordFieldType,
): RecordConditionOperator[] {
    if (ORDERED_TYPES.includes(type)) {
        return ['eq', 'ne', 'lt', 'le', 'ge', 'gt', 'empty', 'not_empty'];
    }

    switch (type) {
        case 'string':
            return ['eq', 'ne', 'empty', 'not_empty'];
        case 'boolean':
            return ['eq'];
        case 'choice':
            return ['eq', 'ne', 'in', 'empty', 'not_empty'];
        case 'user':
        case 'department':
        case 'user_or_department':
        case 'record':
            return ['empty', 'not_empty'];
        default:
            return [];
    }
}

/**
 * 条件に使える項目(明細を除き、使える演算子が 1 つ以上ある項目 = 8.2 の表)。規則の作成の画面の項目の選択肢。
 */
export function conditionFields(module: RecordModuleDefinition) {
    return module.fields.filter(
        (field) => conditionOperatorsOf(field.type).length > 0,
    );
}

/** 比べる値(空は null。真偽は真偽、整数・小数・日時・参照は数、複数選べる参照は数の配列、ほかは文字列) */
type Comparable = string | number | boolean | number[] | null;

/** フォームの日時の欄の時差(Asia/Tokyo) */
const FORM_OFFSET = '+09:00';

/**
 * 欄の値を比べる値にする(サーバーの正規化に合わせて前後の空白を除く。型に合わない値は空として扱う = 送ると型の誤りで止まる)。
 */
function comparableOf(
    field: RecordFieldDefinition,
    draft: RecordFieldDraft,
): Comparable {
    if (field.type === 'boolean') {
        return draft === true;
    }

    if (draft === null || typeof draft === 'boolean') {
        return null;
    }

    // 空の配列は null に揃える(空 = 項目と規則/README.md 2 節。サーバーの評価の入力と同じ)
    if (Array.isArray(draft)) {
        return draft.length === 0 ? null : draft.map((picked) => picked.id);
    }

    if (typeof draft === 'object') {
        return draft.id;
    }

    const text = draft.trim();

    switch (field.type) {
        case 'integer':
            return /^-?[0-9]+$/.test(text) ? Number(text) : null;
        case 'decimal':
            return /^-?[0-9]+(\.[0-9]+)?$/.test(text) ? Number(text) : null;
        case 'datetime': {
            const time = Date.parse(`${text}:00${FORM_OFFSET}`);

            return Number.isNaN(time) ? null : time;
        }
        default:
            return text === '' ? null : text;
    }
}

/**
 * 条件の式の value を、比べる値にする(小数は文字列、日時は UTC の文字列で持つ = 8.2)。
 */
function expectedOf(
    field: RecordFieldDefinition,
    value: RecordConditionExpression['value'],
): Comparable {
    if (value === undefined || Array.isArray(value)) {
        return null;
    }

    switch (field.type) {
        case 'decimal':
            return Number(value);
        case 'datetime':
            return Date.parse(String(value));
        default:
            return value;
    }
}

/**
 * 式 1 つの真偽(空なら empty だけが真 = 8.2 の「空のときの意味」)。
 */
function holds(
    expression: RecordConditionExpression,
    field: RecordFieldDefinition,
    actual: Comparable,
): boolean {
    if (expression.op === 'empty' || expression.op === 'not_empty') {
        return (actual === null) === (expression.op === 'empty');
    }

    // 複数選べる参照に使える演算子は empty・not_empty だけ(8.2 の表。型に使えない式は isRequiredNow が空として評価する)
    if (actual === null || Array.isArray(actual)) {
        return false;
    }

    if (expression.op === 'in') {
        return (
            Array.isArray(expression.value) &&
            expression.value.some((code) => code === actual)
        );
    }

    const expected = expectedOf(field, expression.value);

    if (expected === null) {
        return false;
    }

    switch (expression.op) {
        case 'eq':
            return actual === expected;
        case 'ne':
            return actual !== expected;
        case 'lt':
            return actual < expected;
        case 'le':
            return actual <= expected;
        case 'ge':
            return actual >= expected;
        case 'gt':
            return actual > expected;
    }
}

/**
 * 項目が今の入力で必須か(項目の必須か、対象にする条件つき必須の規則の条件がすべて真)。values は同じ業務モジュール(明細なら明細の
 * モジュールの 1 行)の欄の値。明細の項目の必須は 1 行以上の意味。
 */
export function isRequiredNow(
    module: RecordModuleDefinition,
    field: RecordFieldDefinition,
    values: Record<string, RecordFieldDraft>,
): boolean {
    if (field.isRequired) {
        return true;
    }

    return module.rules.some(
        (rule) =>
            rule.kind === 'required_when' &&
            rule.target === field.code &&
            rule.condition.all.every((expression) => {
                const conditionField = module.fields.find(
                    (candidate) => candidate.code === expression.field,
                );

                // 項目が無い式と、演算子が型に使えない式は値を空として評価する(8.2)。値の形は確かめない(送った後の 422 が正 = 12 節)
                if (
                    conditionField === undefined ||
                    !conditionOperatorsOf(conditionField.type).includes(
                        expression.op,
                    )
                ) {
                    return expression.op === 'empty';
                }

                return holds(
                    expression,
                    conditionField,
                    comparableOf(
                        conditionField,
                        values[expression.field] ?? null,
                    ),
                );
            }),
    );
}
