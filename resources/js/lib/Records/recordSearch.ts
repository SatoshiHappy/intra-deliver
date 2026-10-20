import type { QueryParams } from '@/wayfinder';
import type { PickedReference, RecordFieldDefinition } from '@/types';

/**
 * 業務の一覧の検索欄の値と、クエリの filter への変換(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.1)。
 */

type Range = { from: string; to: string };

/** 欄の値: 文字列・真偽・選択 = 文字列、範囲 = 下限と上限、参照 = 選んだ相手 */
export type SearchDraft = string | Range | PickedReference | null;

/**
 * 欄の値を、クエリの filter の 1 つの値にする(空は undefined。日時の範囲の秒と時差 = 画面/業務の画面.md 2.1 の「クエリ」)。
 */
export function filterValue(
    field: RecordFieldDefinition,
    draft: SearchDraft,
): QueryParams[string] {
    if (draft === null || draft === '') {
        return undefined;
    }

    if (typeof draft === 'string') {
        return draft;
    }

    if ('id' in draft) {
        return String(draft.id);
    }

    const suffix = (side: 'from' | 'to') =>
        field.type === 'datetime'
            ? side === 'from'
                ? ':00+09:00'
                : ':59+09:00'
            : '';
    const from = draft.from === '' ? undefined : draft.from + suffix('from');
    const to = draft.to === '' ? undefined : draft.to + suffix('to');

    return from === undefined && to === undefined ? undefined : { from, to };
}
