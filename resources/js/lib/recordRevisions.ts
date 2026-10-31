import {
    RESTRICTED_KEY,
    restrictedText,
    VIEWERS_FIELD,
    VIEWERS_KEY,
} from '@/lib/recordRowAccess';
import type {
    RecordFieldDefinition,
    RecordFieldType,
    RecordFieldValue,
    RecordRevisionField,
    RecordRevisionRowAccessChange,
} from '@/types';

/**
 * 更新履歴の変化の値を描く分岐(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.2 節)。
 */

/**
 * 版の定義の写しを、値の部品(RecordValue)に渡す項目の定義にする(描くのに使わない属性は既定の値)。
 */
export function revisionFieldDefinition(
    field: RecordRevisionField,
): RecordFieldDefinition {
    return {
        code: field.code,
        name: field.name,
        type: field.type,
        isRequired: false,
        isMultiline: field.isMultiline,
        isMultiple: false,
        maxLength: null,
        defaultValue: null,
        options: [],
        referencedModule:
            field.referencedModule === null
                ? null
                : { code: field.referencedModule.code, name: '' },
        childModule: null,
        isListed: false,
        isSearchable: false,
        isTitle: false,
        grantsRowAccess: false,
    };
}

type RowValues = Record<string, RecordFieldValue> | null;

/** 明細の行の項目 1 つの値(値の部品 RecordValue に渡す定義と値) */
export type DetailRowCell = {
    name: string;
    definition: RecordFieldDefinition;
    value: RecordFieldValue;
};

/**
 * 明細の行の項目ごとの値(項目の並び。行が無ければ空)。先頭の項目の値が行の表示、後の項目の「<名前> <値>」が行の値の要約
 * (5.2 の「明細」。値は値の部品で描く = 種類を持つ値に種類の印が付く)。
 */
export function detailRowCells(
    fields: RecordRevisionField[],
    values: RowValues,
): DetailRowCell[] {
    if (values === null) {
        return [];
    }

    return fields.map((field) => ({
        name: field.name,
        definition: revisionFieldDefinition(field),
        value: values[field.code] ?? null,
    }));
}

/** 参照制限の変化の 1 行(項目の変化と同じ部品で描く) */
export type RowAccessLine = {
    code: string;
    field: RecordRevisionField;
    before: RecordFieldValue;
    after: RecordFieldValue;
};

/**
 * 参照制限の変化の行(5.2 の「参照制限」。チェックが違えば「参照制限」、参照者の集合が違えば「参照者」の順。変化が無ければ空)。
 * 参照者は型「ユーザーと組織(複数)」の値として描く(相手の種類の出し方と 0 人の「—」が同じになる)。
 */
export function rowAccessLines(
    change: RecordRevisionRowAccessChange | null,
): RowAccessLine[] {
    const lines: RowAccessLine[] = [];
    const field = (code: string, name: string, type: RecordFieldType) => ({
        code,
        name,
        type,
        isMultiline: false,
        referencedModule: null,
    });

    if (change?.restricted) {
        lines.push({
            code: RESTRICTED_KEY,
            field: field(RESTRICTED_KEY, '参照制限', 'string'),
            before: restrictedText(change.restricted.before),
            after: restrictedText(change.restricted.after),
        });
    }

    if (change?.viewers) {
        lines.push({
            code: VIEWERS_KEY,
            field: field(VIEWERS_KEY, VIEWERS_FIELD.name, VIEWERS_FIELD.type),
            before: change.viewers.before,
            after: change.viewers.after,
        });
    }

    return lines;
}
