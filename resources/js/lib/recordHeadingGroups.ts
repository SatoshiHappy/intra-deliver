import type { RecordHeadingGroup } from '@/types';

/**
 * 見出しのまとまりの畳みの規則(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 2・3 節)。
 * 畳んだ見出しは見出しの id の配列で持つ(既定 = 空 = 全部開く。保たない = 訪問のたびに空から始め、URL にも映さない)。
 */

/** 見出しの行を持つまとまりか(頭のまとまりは見出しが無く、畳めない) */
export function isHeadingGroup(
    group: RecordHeadingGroup,
): group is RecordHeadingGroup & { id: number; name: string } {
    return group.id !== null && group.name !== null;
}

/** 畳んだ見出しの id の配列から、押した見出しを畳む・開くを入れ替えた配列 */
export function toggleClosed(closed: number[], id: number): number[] {
    return closed.includes(id)
        ? closed.filter((closedId) => closedId !== id)
        : [...closed, id];
}

/**
 * 項目のコードのどれかを含む見出しの id(保存の誤りの項目を含む見出しを開くため。見出しの無い頭のまとまりは畳めないので含めない)。
 */
export function headingIdsContaining(
    groups: RecordHeadingGroup[],
    codes: string[],
): number[] {
    return groups
        .filter(isHeadingGroup)
        .filter((group) =>
            group.fieldCodes.some((code) => codes.includes(code)),
        )
        .map((group) => group.id);
}

/** 畳んだ見出しの id の配列から、指定した見出しを開いた配列(指定に無い見出しは今の開閉のまま) */
export function withOpened(closed: number[], ids: number[]): number[] {
    return closed.filter((id) => !ids.includes(id));
}
