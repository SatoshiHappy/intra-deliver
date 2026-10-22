import { Link } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordHeadingRow } from '@/components/Records/RecordHeadingRow';
import { SortHandle, useSortableRow } from '@/components/Records/SortableList';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import { itemId } from '@/lib/Records/adminItems';
import { fieldAttributeBadges } from '@/lib/Records/fieldAttributeBadges';
import { fieldTypeLabel } from '@/lib/Records/recordDefinitions';
import {
    destroy as destroyField,
    edit as editField,
} from '@/routes/records/admin/fields';
import { show } from '@/routes/records/admin/modules';
import type {
    RecordAdminFieldDefinition,
    RecordAdminHeading,
    RecordAdminItem,
    RecordAdminModule,
    RecordFieldDefinition,
} from '@/types';

/**
 * 項目の削除の確かめの文(画面/管理の画面.md 3.3)。
 */
function deleteDescription(field: RecordFieldDefinition, count: number) {
    return field.type === 'details'
        ? `明細のモジュール「${field.childModule?.name ?? field.name}」(項目と規則を含む)と、${count} 行の明細が削除されます。`
        : `項目「${field.name}」を削除すると、${count} 件のレコードからこの項目の値が削除されます。`;
}

/**
 * 項目の属性の札(画面/管理の画面.md 3.3)。長い既定値の札は 2 行で切る(画面/README.md 1.4)。既定値の札だけを特別に扱わないよう、
 * どの札にも同じ形を当てる(短い札の見た目は変わらない)。
 */
function AttributeBadges({ field }: { field: RecordAdminFieldDefinition }) {
    return (
        <div className="flex flex-wrap gap-1">
            {fieldAttributeBadges(field).map((badge) => (
                <Badge
                    key={badge}
                    variant="secondary"
                    className="max-w-xl whitespace-normal"
                >
                    <span className="line-clamp-2 wrap-anywhere">{badge}</span>
                </Badge>
            ))}
        </div>
    );
}

/**
 * 項目の表の 1 行(先頭の取っ手でつかんで動かす。取っ手だけが並び替えを始める)。
 */
function FieldRow({
    module,
    field,
    usage,
}: {
    module: RecordAdminModule;
    field: RecordAdminFieldDefinition;
    usage: number;
}) {
    const { setNodeRef, style, rowClassName, handle } = useSortableRow(
        itemId({ kind: 'field', code: field.code }),
    );

    return (
        <TableRow ref={setNodeRef} style={style} className={rowClassName}>
            <TableCell className="w-0 pr-0">
                <SortHandle
                    handle={handle}
                    label={`${field.name}の並び順を変更`}
                />
            </TableCell>
            <TableCell>{field.name}</TableCell>
            <TableCell className="text-muted-foreground">
                {field.code}
            </TableCell>
            <TableCell>
                {field.childModule === null ? (
                    fieldTypeLabel(field)
                ) : (
                    <Link
                        href={show(field.childModule.code)}
                        className="text-primary underline-offset-4 hover:underline"
                    >
                        {fieldTypeLabel(field)}({field.childModule.name})
                    </Link>
                )}
            </TableCell>
            <TableCell className="whitespace-normal">
                <AttributeBadges field={field} />
            </TableCell>
            <TableCell>
                <div className="flex items-center justify-end gap-1">
                    <Button asChild variant="ghost" size="sm">
                        <Link
                            href={editField({
                                module: module.code,
                                field: field.code,
                            })}
                            aria-label={`${field.name}を編集`}
                        >
                            編集
                        </Link>
                    </Button>
                    <ConfirmDeleteDialog
                        preserveScroll
                        triggerLabel={`${field.name}を削除`}
                        title="項目の削除"
                        description={deleteDescription(field, usage)}
                        action={destroyField({
                            module: module.code,
                            field: field.code,
                        })}
                    />
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * 並びの要素 1 つの行(見出し = 見出しの行、項目 = 項目の行)。
 */
export function ItemRow({
    module,
    item,
    headings,
    counts,
    fieldUsage,
    nameMaxLength,
}: {
    module: RecordAdminModule;
    headings: RecordAdminHeading[];
    fieldUsage: Record<string, number>;
    nameMaxLength: number;
    item: RecordAdminItem;
    /** 見出しの id → 次の見出しの前までの項目の数 */
    counts: Map<number, number>;
}) {
    if (item.kind === 'heading') {
        const heading = headings.find((row) => row.id === item.id);

        return heading === undefined ? null : (
            <RecordHeadingRow
                moduleCode={module.code}
                heading={heading}
                fieldCount={counts.get(item.id) ?? 0}
                nameMaxLength={nameMaxLength}
            />
        );
    }

    const field = module.fields.find((row) => row.code === item.code);

    return field === undefined ? null : (
        <FieldRow
            module={module}
            field={field}
            usage={fieldUsage[field.code] ?? 0}
        />
    );
}

/**
 * 並び替えの読み上げの名前(行の ID → 見出しの名前か項目の名前)。
 */
export function nameOfItem(
    id: string,
    module: RecordAdminModule,
    headings: RecordAdminHeading[],
): string {
    return (
        headings.find(
            (heading) => itemId({ kind: 'heading', id: heading.id }) === id,
        )?.name ??
        module.fields.find(
            (field) => itemId({ kind: 'field', code: field.code }) === id,
        )?.name ??
        ''
    );
}
