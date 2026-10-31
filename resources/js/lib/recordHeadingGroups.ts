import type { RecordHeadingGroup } from '@/types';

/**
 * 見出しのまとまりの畳みの規則(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 2・3 節)。
 * 畳んだ見出しは見出しの id の配列で持つ(頭のまとまりの id は null。既定 = 空 = 全部開く。保たない = 訪問のたびに空から始め、URL にも映さない)。
 */

/** 見出しのまとまりの id(頭のまとまり = null) */
export type HeadingKey = RecordHeadingGroup['id'];

/** 頭のまとまり(最初の見出しより前の項目)の見出しの固定の文 */
export const HEAD_GROUP_NAME = '基本情報';

/** まとまりの見出しの行の文(頭のまとまりは固定の「基本情報」) */
export function headingNameOf(group: RecordHeadingGroup): string {
    return group.name ?? HEAD_GROUP_NAME;
}

/** 畳んだ見出しの id の配列から、押した見出しを畳む・開くを入れ替えた配列 */
export function toggleClosed(
    closed: HeadingKey[],
    id: HeadingKey,
): HeadingKey[] {
    return closed.includes(id)
        ? closed.filter((closedId) => closedId !== id)
        : [...closed, id];
}

/**
 * 項目のコードのどれかを含む見出しの id(保存の誤りの項目を含む見出しを開くため。頭のまとまりも含め、その id は null)。
 */
export function headingIdsContaining(
    groups: RecordHeadingGroup[],
    codes: string[],
): HeadingKey[] {
    return groups
        .filter((group) =>
            group.fieldCodes.some((code) => codes.includes(code)),
        )
        .map((group) => group.id);
}

/** 畳んだ見出しの id の配列から、指定した見出しを開いた配列(指定に無い見出しは今の開閉のまま) */
export function withOpened(
    closed: HeadingKey[],
    ids: HeadingKey[],
): HeadingKey[] {
    return closed.filter((id) => !ids.includes(id));
}
