import { revisionFieldDefinition } from '@/lib/recordRevisions';
import type {
    RecordFieldDefinition,
    RecordHeadingGroup,
    RecordRevisionView,
} from '@/types';

/**
 * 版の写し(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節の RecordRevisionView)を、詳細の部品に渡す形にする分岐。
 */

/** 版の写しの見出しのまとまり(見出しの部品に渡す形。項目の並びはまとまりごと) */
export function revisionHeadingGroups(
    view: RecordRevisionView,
): RecordHeadingGroup[] {
    return view.groups.map((group) => ({
        id: group.id,
        name: group.name,
        fieldCodes: group.fields.map((field) => field.code),
    }));
}

/** 見出しのまとまり 1 つの項目の定義(版の写しの並びのまま) */
export function revisionGroupFields(
    view: RecordRevisionView,
    id: RecordHeadingGroup['id'],
): RecordFieldDefinition[] {
    return (view.groups.find((group) => group.id === id)?.fields ?? []).map(
        revisionFieldDefinition,
    );
}

/**
 * 版の写しの明細 1 つを、明細の帯の部品に渡す項目の定義にする(列 = 版の写しの明細の項目)。
 */
export function revisionDetailField(
    detail: RecordRevisionView['details'][number],
): RecordFieldDefinition {
    return {
        ...revisionFieldDefinition({
            code: detail.code,
            name: detail.name,
            type: 'details',
            isMultiline: false,
            referencedModule: null,
        }),
        childModule: {
            code: detail.code,
            name: detail.name,
            description: null,
            fields: detail.fields.map(revisionFieldDefinition),
            rules: [],
        },
    };
}

/**
 * 版の写しの明細の行(明細のコード → 行)。
 */
export function revisionDetailRows(
    view: RecordRevisionView,
): Record<string, RecordRevisionView['details'][number]['rows']> {
    return Object.fromEntries(
        view.details.map((detail) => [detail.code, detail.rows]),
    );
}
