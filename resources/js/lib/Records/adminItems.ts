import type { RecordAdminItem, RecordAdminModule } from '@/types';

/**
 * 管理の項目の表の行 = 見出しと項目の 1 本の並び(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 4・5 節、
 * 業務モジュールの管理.md の 6 節の items)。並び替えの行の ID と、見出しごとの項目の数。
 */

/** 行の ID(DndContext の ID。見出しと項目で重ならないよう種類を前に付ける) */
export function itemId(item: RecordAdminItem): string {
    return item.kind === 'heading'
        ? `heading:${item.id}`
        : `field:${item.code}`;
}

/** 見出しの項目の数に数える項目か(明細の項目は見出しに属さない) */
export function isCountedField(
    module: RecordAdminModule,
): (code: string) => boolean {
    return (code) =>
        module.fields.find((field) => field.code === code)?.type !== 'details';
}

/**
 * 見出しごとの項目の数(見出しの id → 次の見出しの前までの項目の数。isCounted が偽の項目 = 明細の項目は数えない)。
 * 並び替えの途中は手元の並びで数える。
 */
export function headingFieldCounts(
    items: RecordAdminItem[],
    isCounted: (code: string) => boolean,
): Map<number, number> {
    const counts = new Map<number, number>();
    let current: number | null = null;

    for (const item of items) {
        if (item.kind === 'heading') {
            current = item.id;
            counts.set(item.id, 0);
        } else if (current !== null && isCounted(item.code)) {
            counts.set(current, (counts.get(current) ?? 0) + 1);
        }
    }

    return counts;
}
