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

/**
 * 見出しのまとまりの項目の数に数える項目か(明細の項目と、見出しを持たない明細のモジュールの項目は数えない)。
 */
export function isCountedField(
    module: RecordAdminModule,
): (code: string) => boolean {
    return (code) =>
        module.parent === null &&
        module.fields.find((field) => field.code === code)?.type !== 'details';
}

/**
 * 見出しのまとまりに入る項目(項目のコード → 見出しの id。頭のまとまり = 最初の見出しより前は null。次の見出しの前までの項目。
 * isCounted が偽の項目は入らない)。並び替えの途中は手元の並びで決める。
 */
export function headingOfFields(
    items: RecordAdminItem[],
    isCounted: (code: string) => boolean,
): Map<string, number | null> {
    const headingOf = new Map<string, number | null>();
    let current: number | null = null;

    for (const item of items) {
        if (item.kind === 'heading') {
            current = item.id;
        } else if (isCounted(item.code)) {
            headingOf.set(item.code, current);
        }
    }

    return headingOf;
}

/**
 * 見出しのまとまり。counts = 見出しの id → その見出しに入る項目の数(項目が 0 の見出しは 0)、
 * headCount = 頭のまとまりの項目の数(固定の行「基本情報」の「<n> 項目」)、headingOf = {@link headingOfFields}。
 */
export type HeadingGrouping = {
    counts: Map<number, number>;
    headCount: number;
    headingOf: Map<string, number | null>;
};

/**
 * 管理の項目の表が使う見出しのまとまり(見出しと頭の「<n> 項目」と、項目の名前の字下げを同じ並びから決める。字下げ = headingOf に入る項目)。
 */
export function headingGrouping(
    items: RecordAdminItem[],
    isCounted: (code: string) => boolean,
): HeadingGrouping {
    const headingOf = headingOfFields(items, isCounted);
    const counts = new Map<number, number>();
    let headCount = 0;

    for (const item of items) {
        if (item.kind === 'heading') {
            counts.set(item.id, 0);
        }
    }

    for (const id of headingOf.values()) {
        if (id === null) {
            headCount += 1;
        } else {
            counts.set(id, (counts.get(id) ?? 0) + 1);
        }
    }

    return { counts, headCount, headingOf };
}
