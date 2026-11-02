import { useState } from 'react';
import { clearedStateOf, nextClearedStateOf } from '@/lib/clearedFields';
import type { ClearedMark, ClearedValues } from '@/lib/clearedFields';

/**
 * 空にした欄の印の組を、フォームの値が変わるたびに表 1 で確かめて返す(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の
 * 「空にした項目の知らせ」)。どの経路で値が変わっても描画の中の 1 か所で当てる(前の描画の値と比べて状態を更新する React の作法)。
 * sources = 仕組みごとの印(後の組ほど後から写した仕組み)。組の配列が替わったら、その組は新しい印で置き換える。
 */
export function useClearedMarks(
    sources: ClearedMark[][],
    current: ClearedValues,
): ClearedMark[][] {
    const [state, setState] = useState(() => clearedStateOf(sources, current));
    const next = nextClearedStateOf(state, sources, current);

    if (next !== state) {
        setState(next);
    }

    return next.groups;
}
