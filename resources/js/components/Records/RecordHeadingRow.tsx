import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordHeadingDialog } from '@/components/Records/RecordHeadingDialog';
import { SortHandle, useSortableRow } from '@/components/Records/SortableList';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import { itemId } from '@/lib/Records/adminItems';
import { HEAD_GROUP_NAME } from '@/lib/recordHeadingGroups';
import { destroy } from '@/routes/records/admin/headings';
import { cn } from '@/lib/utils';
import type { RecordAdminHeading } from '@/types';

type Props = {
    moduleCode: string;
    heading: Pick<RecordAdminHeading, 'id' | 'name'>;
    /** 次の見出しの前までの項目の数(明細の項目を除く) */
    fieldCount: number;
    nameMaxLength: number;
};

/**
 * 見出しの行の操作(編集 = Dialog、削除 = 確かめの Dialog)。
 */
function HeadingActions({
    moduleCode,
    heading,
    nameMaxLength,
}: Omit<Props, 'fieldCount'>) {
    return (
        <div className="flex items-center justify-end gap-1">
            <RecordHeadingDialog
                moduleCode={moduleCode}
                heading={heading}
                nameMaxLength={nameMaxLength}
                trigger={
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={`見出し「${heading.name}」を編集`}
                    >
                        編集
                    </Button>
                }
            />
            <ConfirmDeleteDialog
                preserveScroll
                triggerLabel={`見出し「${heading.name}」を削除`}
                title="見出しの削除"
                description={`見出し「${heading.name}」を削除しますか。見出しの中の項目は削除されず、前の見出しのまとまりに入ります。`}
                action={destroy({ module: moduleCode, heading: heading.id })}
            />
        </div>
    );
}

/**
 * 管理の項目の表の見出しの行(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 5 節): 取っ手・名前・「<n> 項目」・
 * 操作(編集 = Dialog、削除 = 確かめの Dialog)。項目の行と同じ表の中に並びの位置で出し、背景で区別する。取っ手でほかの行と同じように動かせる。
 */
export function RecordHeadingRow({
    moduleCode,
    heading,
    fieldCount,
    nameMaxLength,
}: Props) {
    const { setNodeRef, style, rowClassName, handle } = useSortableRow(
        itemId({ kind: 'heading', id: heading.id }),
    );

    return (
        <TableRow
            ref={setNodeRef}
            style={style}
            className={cn('bg-muted/50 hover:bg-muted/50', rowClassName)}
        >
            <TableCell className="w-0 pr-0">
                <SortHandle
                    handle={handle}
                    label={`見出し「${heading.name}」の並び順を変更`}
                />
            </TableCell>
            <TableCell colSpan={4} className="whitespace-normal">
                <span className="font-semibold wrap-anywhere">
                    {heading.name}
                </span>
                <span className="ml-2 text-xs text-muted-foreground">
                    {fieldCount} 項目
                </span>
            </TableCell>
            <TableCell>
                <HeadingActions
                    moduleCode={moduleCode}
                    heading={heading}
                    nameMaxLength={nameMaxLength}
                />
            </TableCell>
        </TableRow>
    );
}

/**
 * 管理の項目の表の固定の行「基本情報」(最初の見出しより前の項目のまとまり。見出しの行と同じ背景・名前・「<n> 項目」。
 * 取っ手も操作も無い = 動かせない・名前を変えられない・消せない。並び替えの並びには入れない。
 * 明細でない業務モジュールなら、頭のまとまりが空でも「0 項目」で出す = docs の 見出し.md の 1 節)。
 */
export function RecordHeadGroupRow({ fieldCount }: { fieldCount: number }) {
    return (
        <TableRow className="bg-muted/50 hover:bg-muted/50">
            <TableCell className="w-0 pr-0" />
            <TableCell colSpan={4} className="whitespace-normal">
                <span className="font-semibold">{HEAD_GROUP_NAME}</span>
                <span className="ml-2 text-xs text-muted-foreground">
                    {fieldCount} 項目
                </span>
            </TableCell>
            <TableCell />
        </TableRow>
    );
}
