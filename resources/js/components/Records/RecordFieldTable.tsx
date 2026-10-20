import type { DragEndEvent } from '@dnd-kit/core';
import { arrayMove } from '@dnd-kit/sortable';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import {
    moveWithin,
    SortableArea,
    SortableRows,
    SortHandle,
    useSortableRow,
} from '@/components/Records/SortableList';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { fieldAttributeBadges } from '@/lib/Records/fieldAttributeBadges';
import { fieldTypeLabel } from '@/lib/Records/recordDefinitions';
import { putOrder } from '@/lib/Records/sortOrder';
import {
    destroy as destroyField,
    edit as editField,
    reorder,
} from '@/routes/records/admin/fields';
import { show } from '@/routes/records/admin/modules';
import type {
    RecordAdminFieldDefinition,
    RecordAdminModule,
    RecordFieldDefinition,
} from '@/types';

type Props = {
    module: RecordAdminModule;
    /** 項目のコード → 値を持つレコード(明細は明細の行)の数 */
    fieldUsage: Record<string, number>;
};

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
        field.code,
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
 * 管理の項目の表(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3。明細のモジュールの定義の画面も同じ部品): 列 = 取っ手・名前・コード・型・属性・操作。
 * 行の順が項目の並び。取っ手をつかんで(マウス、またはフォーカスして Space → 矢印 → Space)動かすと、画面の並びを先に変えて
 * records.admin.fields.reorder へ全項目のコードを新しい順に送り、module と fieldUsage だけを読み直す。要求が終わったら(成否に依らず)
 * 手元の並びを捨てて props に戻る。成功はトーストなし、422 は文言をトースト、応答が無いときもトースト。送っている間は次のドラッグを受けない。
 */
export function RecordFieldTable({ module, fieldUsage }: Props) {
    // 要求の間だけの手元の並び(null = props の並び)。要求の間は props の変化を見ない
    // (Inertia は props を更新してから onSuccess・onError を呼び、最後に onFinish を呼ぶ = そこで手元の並びを捨てて props に戻る)
    const [pending, setPending] = useState<RecordAdminFieldDefinition[] | null>(
        null,
    );
    const fields = pending ?? module.fields;
    const codes = fields.map((field) => field.code);

    const drop = ({ active, over }: DragEndEvent) => {
        const move = moveWithin(codes, active.id, over?.id ?? null);

        if (pending !== null || move === null) {
            return;
        }

        const next = arrayMove(fields, ...move);
        setPending(next);
        putOrder(
            reorder(module.code).url,
            { codes: next.map((field) => field.code) },
            ['module', 'fieldUsage'],
            () => setPending(null),
        );
    };

    return (
        <SortableArea
            id={`record-fields-${module.code}`}
            nameOf={(id) =>
                fields.find((field) => field.code === id)?.name ?? ''
            }
            onDragEnd={drop}
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-0 pr-0">
                            <span className="sr-only">並び替え</span>
                        </TableHead>
                        <TableHead>名前</TableHead>
                        <TableHead>コード</TableHead>
                        <TableHead>型</TableHead>
                        <TableHead>属性</TableHead>
                        <TableHead>
                            <span className="sr-only">操作</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <SortableRows
                        id={`record-fields-${module.code}`}
                        ids={codes}
                        disabled={pending !== null}
                    >
                        {fields.map((field) => (
                            <FieldRow
                                key={field.code}
                                module={module}
                                field={field}
                                usage={fieldUsage[field.code] ?? 0}
                            />
                        ))}
                    </SortableRows>
                </TableBody>
            </Table>
        </SortableArea>
    );
}
