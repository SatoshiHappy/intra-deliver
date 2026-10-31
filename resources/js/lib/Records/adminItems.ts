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
 * 見出しのまとまりに入る項目(項目のコード → 見出しの id。次の見出しの前までの項目。頭の項目と、isCounted が偽の項目 =
 * 明細の項目は入らない)。並び替えの途中は手元の並びで決める。
 */
export function headingOfFields(
    items: RecordAdminItem[],
    isCounted: (code: string) => boolean,
): Map<string, number> {
    const headingOf = new Map<string, number>();
    let current: number | null = null;

    for (const item of items) {
        if (item.kind === 'heading') {
            current = item.id;
        } else if (current !== null && isCounted(item.code)) {
            headingOf.set(item.code, current);
        }
    }

    return headingOf;
}

/**
 * 見出しごとの項目の数(見出しの id → {@link headingOfFields} でその見出しに入る項目の数。項目が 0 の見出しは 0)。
 */
export function headingFieldCounts(
    items: RecordAdminItem[],
    isCounted: (code: string) => boolean,
): Map<number, number> {
    const counts = new Map<number, number>();

    for (const item of items) {
        if (item.kind === 'heading') {
            counts.set(item.id, 0);
        }
    }

    for (const id of headingOfFields(items, isCounted).values()) {
        counts.set(id, (counts.get(id) ?? 0) + 1);
    }

    return counts;
}

/** 見出しのまとまり(counts = {@link headingFieldCounts}、headingOf = {@link headingOfFields}) */
export type HeadingGrouping = {
    counts: Map<number, number>;
    headingOf: Map<string, number>;
};

/**
 * 管理の項目の表が使う見出しのまとまり(見出しの「<n> 項目」と、項目の名前の字下げを同じ並びから決める)。
 */
export function headingGrouping(
    items: RecordAdminItem[],
    isCounted: (code: string) => boolean,
): HeadingGrouping {
    return {
        counts: headingFieldCounts(items, isCounted),
        headingOf: headingOfFields(items, isCounted),
    };
}
