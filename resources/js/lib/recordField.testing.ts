import type { RecordFieldDefinition, RecordFieldType } from '@/types';

/**
 * 単体テストが使う項目の定義(名前 = コード、属性は既定の値)。テストごとに違う値は overrides で渡す。
 * テストのファイルごとに組み立てを写すと、前提が少しずつ食い違うので 1 つにした。
 * 名前が `.testing.ts` のファイルは `*.test.ts` からだけ import できる(本番のコードへ既定値が入り込まないよう、deps:check が止める)。
 */
export function recordField(
    code: string,
    type: RecordFieldType,
    overrides: Partial<RecordFieldDefinition> = {},
): RecordFieldDefinition {
    return {
        code,
        name: code,
        type,
        isRequired: false,
        isMultiline: false,
        isMultiple: false,
        maxLength: null,
        defaultValue: null,
        options: [],
        referencedModule: null,
        childModule: null,
        isListed: true,
        isSearchable: true,
        isTitle: false,
        grantsRowAccess: false,
        ...overrides,
    };
}
