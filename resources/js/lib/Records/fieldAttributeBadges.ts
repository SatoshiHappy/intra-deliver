import { relativeDefaultText } from '@/lib/Records/relativeDefault';
import { formatRecordValue } from '@/lib/recordValues';
import type { RecordAdminFieldDefinition } from '@/types';

/**
 * 項目の表の属性の札(設計/業務データの入れ物/画面/管理の画面.md の 3.3: 必須・一覧・検索・表示の項目・関係者・既定値)。
 */
export function fieldAttributeBadges(
    field: RecordAdminFieldDefinition,
): string[] {
    const defaultText = defaultValueText(field);

    return [
        field.isRequired ? '必須' : null,
        field.isListed ? '一覧' : null,
        field.isSearchable ? '検索' : null,
        field.isTitle ? '表示の項目' : null,
        field.grantsRowAccess ? '関係者' : null,
        defaultText === null ? null : `既定値: ${defaultText}`,
    ].filter((badge) => badge !== null);
}

/**
 * 既定値の表示(相対の既定値は「登録の時から <数> <単位>後」、選択は名称。ほかは値の表示と同じ = 設計/業務データの入れ物/画面/README.md 1.3。
 * 札は表のセルの見え方で、複数行は平文の先頭 = 同 1.4)。既定値が無ければ null。
 */
function defaultValueText(field: RecordAdminFieldDefinition): string | null {
    const value = field.defaultValue;

    if (field.relativeDefault !== null) {
        return relativeDefaultText(field.relativeDefault);
    }

    if (value === null) {
        return null;
    }

    return field.type === 'choice'
        ? (field.options.find((option) => option.code === value)?.name ??
              String(value))
        : formatRecordValue(field, value, 'cell');
}
