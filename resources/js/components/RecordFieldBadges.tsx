import { Badge } from '@/components/ui/badge';
import { TableHead } from '@/components/ui/table';
import { fieldBadges } from '@/lib/recordFieldBadges';
import type { RecordFieldDefinition } from '@/types';

/** 札を決める項目の属性 */
type BadgeField = Pick<RecordFieldDefinition, 'name' | 'grantsRowAccess'>;

/**
 * 入力の欄のラベルの横の札({@link fieldBadges}。「必須」と「関係者」)。どちらも無ければ何も描かない。
 */
export function RecordFieldBadges({
    field,
    required,
}: {
    field: BadgeField;
    required: boolean;
}) {
    return fieldBadges(field, required).map((label) => (
        <Badge key={label} variant="secondary">
            {label}
        </Badge>
    ));
}

/**
 * 読み取りの表示と明細の表の列の見出しの項目の名前(関係者の印があれば名前の横に札「関係者」)。
 */
export function RecordFieldName({ field }: { field: BadgeField }) {
    return field.grantsRowAccess ? (
        <span className="inline-flex items-center gap-2">
            {field.name}
            <RecordFieldBadges field={field} required={false} />
        </span>
    ) : (
        field.name
    );
}

/**
 * 明細の表の列の見出し(列 = 明細のモジュールの項目。関係者の印の付いた列は名前の横に札「関係者」)。
 */
export function RecordColumnHeads({
    columns,
}: {
    columns: (BadgeField & Pick<RecordFieldDefinition, 'code'>)[];
}) {
    return columns.map((column) => (
        <TableHead key={column.code}>
            <RecordFieldName field={column} />
        </TableHead>
    ));
}
