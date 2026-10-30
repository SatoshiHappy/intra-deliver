import { describe, expect, it } from 'vite-plus/test';
import type {
    RecordConditionExpression,
    RecordFieldDefinition,
    RecordFieldType,
    RecordModuleDefinition,
} from '@/types';
import shared from '../../../tests/Feature/Actions/Records/record-condition-expressions.json';
import { recordField } from './recordField.testing';
import type { RecordFieldDraft } from './recordForm';
import type { RecordPayloadValue } from './recordPayload';
import { payloadValue } from './recordPayload';
import { conditionOperatorsOf, isRequiredNow } from './recordRules';

/**
 * サーバーの評価のテスト(RecordRuleEvaluationTest・MultipleReferenceRuleEvaluationTest)と共有する表の 1 行。
 * isMultiple = 複数選べる参照の項目の行(無ければ偽)。
 */
type SharedRow = {
    type: RecordFieldType;
    isMultiple?: boolean;
    op: RecordConditionExpression['op'];
    value: RecordConditionExpression['value'] | null;
    server: RecordPayloadValue;
    form: RecordFieldDraft;
    holds: boolean;
};

const rows = shared as Record<string, SharedRow>;

/**
 * サーバーの入力を、画面が送る形にする(画面は 0 個を null で送る = 画面/業務の画面.md 2.2 の「複数の参照の欄」。
 * サーバーは空の配列を null と同じに読む = 項目と規則/README.md 2 節)。
 */
function sentForm(server: SharedRow['server']): RecordPayloadValue {
    return Array.isArray(server) && server.length === 0 ? null : server;
}

function field(
    code: string,
    type: RecordFieldType,
    isMultiple = false,
): RecordFieldDefinition {
    return recordField(code, type, {
        isMultiple,
        options:
            type === 'choice'
                ? ['open', 'done', 'hold'].map((option) => ({
                      code: option,
                      name: option,
                  }))
                : [],
    });
}

/** 項目 target と、条件の項目(fields)、target を対象にする条件つき必須の規則 1 つ(条件 = expression)の業務モジュール */
function moduleWith(
    fields: RecordFieldDefinition[],
    expression: RecordConditionExpression,
): RecordModuleDefinition {
    return {
        code: 'projects',
        name: 'projects',
        description: null,
        fields: [field('target', 'string'), ...fields],
        rules: [
            {
                id: 1,
                kind: 'required_when',
                target: 'target',
                condition: { all: [expression] },
                message: null,
            },
        ],
    };
}

function expressionOf(
    fieldCode: string,
    op: RecordConditionExpression['op'],
    value: RecordConditionExpression['value'] | null = null,
): RecordConditionExpression {
    return value === null
        ? { field: fieldCode, op }
        : { field: fieldCode, op, value };
}

describe('isRequiredNow', () => {
    it.each(Object.entries(rows))('%s(サーバーの評価と同じ表)', (_, row) => {
        const f = field('f', row.type, row.isMultiple);
        const module = moduleWith([f], expressionOf('f', row.op, row.value));

        expect(payloadValue(f, row.form)).toEqual(sentForm(row.server));
        expect(isRequiredNow(module, module.fields[0], { f: row.form })).toBe(
            row.holds,
        );
    });

    // 対の正本 = RecordConditionsTest::test_an_expression_on_a_deleted_field_or_a_details_field_is_evaluated_as_empty
    it('条件の項目が今の定義に無い式と明細の項目の式は、empty だけが真', () => {
        const requiredBy = (
            fields: RecordFieldDefinition[],
            expression: RecordConditionExpression,
        ) => {
            const module = moduleWith(fields, expression);

            return isRequiredNow(module, module.fields[0], { gone: 'a' });
        };

        expect(requiredBy([], expressionOf('gone', 'empty'))).toBe(true);
        expect(requiredBy([], expressionOf('gone', 'not_empty'))).toBe(false);
        expect(requiredBy([], expressionOf('gone', 'eq', 'a'))).toBe(false);
        expect(
            requiredBy(
                [field('tasks', 'details')],
                expressionOf('tasks', 'empty'),
            ),
        ).toBe(true);
        expect(
            requiredBy(
                [field('tasks', 'details')],
                expressionOf('tasks', 'not_empty'),
            ),
        ).toBe(false);
    });

    it('規則の対象でない項目は、条件が真でも必須にならない', () => {
        const module = moduleWith(
            [field('f', 'string')],
            expressionOf('f', 'not_empty'),
        );

        expect(isRequiredNow(module, module.fields[0], { f: 'a' })).toBe(true);
        expect(isRequiredNow(module, module.fields[1], { f: 'a' })).toBe(false);
    });

    it('相関チェックの規則は条件つき必須に数えない', () => {
        const base = moduleWith(
            [field('f', 'string'), field('limit', 'integer')],
            expressionOf('f', 'not_empty'),
        );
        const module: RecordModuleDefinition = {
            ...base,
            rules: [
                ...base.rules,
                {
                    id: 2,
                    kind: 'compare',
                    target: 'target',
                    operator: 'le',
                    compared: 'limit',
                    message: null,
                },
            ],
        };

        expect(
            isRequiredNow(module, module.fields[0], { f: '', limit: '1' }),
        ).toBe(false);
    });

    it('項目の必須は規則に依らず必須', () => {
        const module = moduleWith([], expressionOf('gone', 'not_empty'));
        const required = { ...field('target', 'string'), isRequired: true };

        expect(isRequiredNow(module, required, {})).toBe(true);
    });
});

describe('conditionOperatorsOf', () => {
    it('参照の型(型「ユーザーと組織」を含む)は空・空でないだけ(8.2 の表)', () => {
        for (const type of [
            'user',
            'department',
            'user_or_department',
            'record',
        ] as const) {
            expect(conditionOperatorsOf(type)).toEqual(['empty', 'not_empty']);
        }
    });

    it('明細は条件に使えない', () => {
        expect(conditionOperatorsOf('details')).toEqual([]);
    });
});
