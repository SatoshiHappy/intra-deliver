import { describe, expect, it } from 'vite-plus/test';
import type {
    RecordConditionExpression,
    RecordFieldDefinition,
    RecordFieldType,
    RecordModuleDefinition,
} from '@/types';
import {
    FIELD_TYPE_CHOICES,
    fieldTypeLabel,
    ruleSummary,
} from './recordDefinitions';

describe('FIELD_TYPE_CHOICES', () => {
    it('「ユーザー」「組織」「ユーザーと組織」のそれぞれ直後に複数の選択肢を置く', () => {
        expect(FIELD_TYPE_CHOICES.map(fieldTypeLabel)).toEqual([
            '文字列(1 行)',
            '文字列(複数行)',
            '整数',
            '小数',
            '日付',
            '日時',
            '真偽',
            '選択',
            'ユーザー',
            'ユーザー(複数)',
            '組織',
            '組織(複数)',
            'ユーザーと組織',
            'ユーザーと組織(複数)',
            '他モジュール参照',
            '明細',
        ]);
    });

    it('複数の選択肢は型 user・department・user_or_department と isMultiple で送る', () => {
        const multiple = FIELD_TYPE_CHOICES.filter(
            (choice) => choice.isMultiple,
        );

        expect(
            multiple.map(({ type, isMultiline, isMultiple }) => ({
                type,
                isMultiline,
                isMultiple,
            })),
        ).toEqual([
            { type: 'user', isMultiline: false, isMultiple: true },
            { type: 'department', isMultiline: false, isMultiple: true },
            {
                type: 'user_or_department',
                isMultiline: false,
                isMultiple: true,
            },
        ]);
        expect(new Set(FIELD_TYPE_CHOICES.map((c) => c.value)).size).toBe(
            FIELD_TYPE_CHOICES.length,
        );
    });
});

describe('fieldTypeLabel', () => {
    it('作った項目の型の名前は is_multiple で分ける', () => {
        const label = (
            type: 'user' | 'department' | 'user_or_department',
            isMultiple: boolean,
        ) => fieldTypeLabel({ type, isMultiline: false, isMultiple });

        expect(label('user', false)).toBe('ユーザー');
        expect(label('user', true)).toBe('ユーザー(複数)');
        expect(label('department', false)).toBe('組織');
        expect(label('department', true)).toBe('組織(複数)');
        expect(label('user_or_department', false)).toBe('ユーザーと組織');
        expect(label('user_or_department', true)).toBe('ユーザーと組織(複数)');
    });
});

/** 条件の文の組が使う項目(PHP の RecordConditionsDescribeTest の業務モジュールと同じ名前・型・選択肢。件名は規則の対象) */
function conditionField(
    code: string,
    name: string,
    type: RecordFieldType,
): RecordFieldDefinition {
    return {
        code,
        name,
        type,
        isRequired: false,
        isMultiline: false,
        isMultiple: false,
        maxLength: null,
        defaultValue: null,
        options:
            type === 'choice'
                ? [
                      { code: 'open', name: '未着手' },
                      { code: 'done', name: '完了' },
                  ]
                : [],
        referencedModule: null,
        childModule: null,
        isListed: false,
        isSearchable: false,
        isTitle: false,
        grantsRowAccess: false,
    };
}

const CONDITION_MODULE: RecordModuleDefinition = {
    code: 'projects',
    name: 'プロジェクト',
    description: null,
    fields: [
        conditionField('budget', '予算(万円)', 'integer'),
        conditionField('rate', '率', 'decimal'),
        conditionField('status', '状態', 'choice'),
        conditionField('owner', '担当者', 'user'),
        conditionField('urgent', '至急', 'boolean'),
        conditionField('start', '開始日', 'date'),
        conditionField('due', '期限', 'datetime'),
        conditionField('title', '件名', 'string'),
    ],
    rules: [],
};

function one(
    field: string,
    op: RecordConditionExpression['op'],
    value?: RecordConditionExpression['value'],
): RecordConditionExpression[] {
    return [value === undefined ? { field, op } : { field, op, value }];
}

/**
 * 式 → 文。サーバーの条件の文(App\Queries\Records\RecordConditionText)と同じ文を作るので、
 * 同じ組を tests/Feature/Queries/Records/RecordConditionsDescribeTest.php にも置く(組を変えるときは両方を変える)。
 */
const CONDITION_TEXTS: [string, RecordConditionExpression[], string][] = [
    ['lt の記号', one('budget', 'lt', 300), '予算(万円) < 300'],
    ['ge の記号', one('budget', 'ge', 300), '予算(万円) ≧ 300'],
    ['le の小数', one('rate', 'le', '1.5'), '率 ≦ 1.5'],
    ['eq の選択肢は名称', one('status', 'eq', 'done'), '状態 が 完了'],
    ['ne の選択肢', one('status', 'ne', 'done'), '状態 が 完了 以外'],
    [
        'in は名称を「・」で',
        one('status', 'in', ['open', 'done']),
        '状態 が 未着手・完了 のどれか',
    ],
    ['無い選択肢はコードのまま', one('status', 'eq', 'hold'), '状態 が hold'],
    ['empty', one('owner', 'empty'), '担当者 が空'],
    ['not_empty', one('owner', 'not_empty'), '担当者 が空でない'],
    ['真偽の真', one('urgent', 'eq', true), '至急 が はい'],
    ['真偽の偽', one('urgent', 'eq', false), '至急 が いいえ'],
    ['日付の表示の形', one('start', 'ge', '2026-10-16'), '開始日 ≧ 2026/10/16'],
    [
        '日時は Asia/Tokyo の表示の形',
        one('due', 'gt', '2026-10-11T00:30:00Z'),
        '期限 > 2026/10/11 09:30',
    ],
    ['消えた項目はコードのまま', one('gone', 'eq', 'a'), 'gone が a'],
    [
        '式どうしは「、」',
        [...one('budget', 'ge', 300), ...one('status', 'eq', 'done')],
        '予算(万円) ≧ 300、状態 が 完了',
    ],
];

describe('ruleSummary: 条件の文', () => {
    it.each(CONDITION_TEXTS)('%s', (_, expressions, text) => {
        expect(
            ruleSummary(CONDITION_MODULE, {
                id: 1,
                kind: 'required_when',
                target: 'title',
                condition: { all: expressions },
                message: null,
            }),
        ).toBe(`${text} のとき、件名 は必須`);
    });
});
