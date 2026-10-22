import type { DragEndEvent } from '@dnd-kit/core';
import { arrayMove } from '@dnd-kit/sortable';
import { useState } from 'react';
import { ItemRow, nameOfItem } from '@/components/Records/RecordFieldRows';
import {
    moveWithin,
    SortableArea,
    SortableRows,
} from '@/components/Records/SortableList';
import {
    Table,
    TableBody,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    headingFieldCounts,
    isCountedField,
    itemId,
} from '@/lib/Records/adminItems';
import { putOrder } from '@/lib/Records/sortOrder';
import { reorder } from '@/routes/records/admin/fields';
import type {
    RecordAdminHeading,
    RecordAdminItem,
    RecordAdminModule,
} from '@/types';

type Props = {
    module: RecordAdminModule;
    /** 見出し(名前。並びは items の中の位置で決まる) */
    headings: RecordAdminHeading[];
    /** 見出しと項目の 1 本の並び(明細のモジュールは項目だけ) */
    items: RecordAdminItem[];
    /** 見出しの名前の文字数の上限 */
    nameMaxLength: number;
    /** 項目のコード → 値を持つレコード(明細は明細の行)の数 */
    fieldUsage: Record<string, number>;
};

/**
 * 表の見出し行(列 = 取っ手・名前・コード・型・属性・操作)。
 */
function FieldTableHead() {
    return (
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
    );
}

/**
 * 管理の項目の表(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3、レコードの共通機能/詳細の画面/見出し.md の 5 節。明細のモジュールの定義の画面も同じ部品):
 * 列 = 取っ手・名前・コード・型・属性・操作。行の順が見出しと項目の 1 本の並び(見出しの行は背景で区別する)。取っ手をつかんで(マウス、
 * またはフォーカスして Space → 矢印 → Space)動かすと、画面の並びを先に変えて records.admin.fields.reorder へ全見出しと全項目を新しい順
 * (items)に送り、module・fieldUsage・headings・items だけを読み直す。要求が終わったら(成否に依らず)手元の並びを捨てて props に戻る。
 * 成功はトーストなし、422 は文言をトースト、応答が無いときもトースト。送っている間は次のドラッグを受けない。
 */
export function RecordFieldTable({
    module,
    headings,
    items,
    nameMaxLength,
    fieldUsage,
}: Props) {
    // 要求の間だけの手元の並び(null = props の並び)。要求の間は props の変化を見ない
    // (Inertia は props を更新してから onSuccess・onError を呼び、最後に onFinish を呼ぶ = そこで手元の並びを捨てて props に戻る)
    const [pending, setPending] = useState<RecordAdminItem[] | null>(null);
    const shown = pending ?? items;
    const ids = shown.map(itemId);
    const counts = headingFieldCounts(shown, isCountedField(module));

    const drop = ({ active, over }: DragEndEvent) => {
        const move = moveWithin(ids, active.id, over?.id ?? null);

        if (pending !== null || move === null) {
            return;
        }

        const next = arrayMove(shown, ...move);
        setPending(next);
        putOrder(
            reorder(module.code).url,
            { items: next },
            ['module', 'fieldUsage', 'headings', 'items'],
            () => setPending(null),
        );
    };

    return (
        <SortableArea
            id={`record-fields-${module.code}`}
            nameOf={(id) => nameOfItem(id, module, headings)}
            onDragEnd={drop}
        >
            <Table>
                <FieldTableHead />
                <TableBody>
                    <SortableRows
                        id={`record-fields-${module.code}`}
                        ids={ids}
                        disabled={pending !== null}
                    >
                        {shown.map((item) => (
                            <ItemRow
                                key={itemId(item)}
                                module={module}
                                item={item}
                                headings={headings}
                                counts={counts}
                                fieldUsage={fieldUsage}
                                nameMaxLength={nameMaxLength}
                            />
                        ))}
                    </SortableRows>
                </TableBody>
            </Table>
        </SortableArea>
    );
}
