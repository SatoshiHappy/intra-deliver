import type {
    RecordFieldDefinition,
    RecordFieldValue,
    RecordRevisionField,
} from '@/types';
import { formatRecordValue } from './recordValues';

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

/**
 * 明細の行の表示(先頭の項目の値。行が無ければ空)。
 */
export function detailRowName(
    fields: RecordRevisionField[],
    values: RowValues,
): string {
    const first = fields[0];

    return first === undefined || values === null
        ? ''
        : formatRecordValue(
              revisionFieldDefinition(first),
              values[first.code] ?? null,
              'diff',
          );
}

/**
 * 明細の行の値の要約(先頭の項目より後の項目の「<名前> <値>」を全角の空白でつなぐ)。
 */
export function detailRowSummary(
    fields: RecordRevisionField[],
    values: RowValues,
): string {
    if (values === null) {
        return '';
    }

    return fields
        .slice(1)
        .map((field) => {
            const definition = revisionFieldDefinition(field);
            const value = values[field.code] ?? null;

            return `${field.name} ${formatRecordValue(definition, value, 'diff')}`;
        })
        .join('　');
}
