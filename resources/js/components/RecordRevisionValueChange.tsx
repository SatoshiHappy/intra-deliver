import { ValueChange } from '@/components/ValueChange';
import { revisionFieldDefinition } from '@/lib/recordRevisions';
import { revisionActorText, revisionOperation } from '@/lib/recordShow';
import { formatDatetime } from '@/lib/recordValues';
import type {
    RecordFieldValue,
    RecordRevisionField,
    RecordRevisionRow,
} from '@/types';

/**
 * 更新履歴の項目 1 つの変化(見せ方は値の変化の部品 {@see ValueChange}。全文の頭 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.2)。
 */
export function RecordRevisionValueChange({
    field,
    before,
    after,
    row,
}: {
    field: RecordRevisionField;
    before: RecordFieldValue;
    after: RecordFieldValue;
    row: RecordRevisionRow;
}) {
    const head = [
        row.at === null ? null : formatDatetime(row.at),
        revisionActorText(row),
        revisionOperation(row).label,
    ];

    return (
        <ValueChange
            field={revisionFieldDefinition(field)}
            before={before}
            after={after}
            fullTextHead={head.filter((part) => part !== null).join('　')}
        />
    );
}
