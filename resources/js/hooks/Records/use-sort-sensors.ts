import {
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { KeyboardCoordinateGetter } from '@dnd-kit/core';
import {
    hasSortableData,
    sortableKeyboardCoordinates,
} from '@dnd-kit/sortable';

/**
 * キーボードのドラッグの矢印の行き先(sortableKeyboardCoordinates)を、つかんだ行と同じ並び(useSortable の containerId)の行だけに絞る。
 * 外枠に並びが 1 つなら sortableKeyboardCoordinates と同じ(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4 = キーボードは同じ親の中だけ)。
 * 候補は位置(droppableRects)の無いものを飛ばすので、ほかの並びの行と見出しの位置を渡さない。
 */
const sameListKeyboardCoordinates: KeyboardCoordinateGetter = (event, args) => {
    const { active, droppableRects, droppableContainers } = args.context;
    const containerOf = (id: Parameters<typeof droppableContainers.get>[0]) => {
        const entry = droppableContainers.get(id);

        return entry !== undefined && hasSortableData(entry)
            ? entry.data.current.sortable.containerId
            : undefined;
    };
    const containerId = active === null ? undefined : containerOf(active.id);
    const rects = new Map(
        [...droppableRects].filter(
            ([id]) =>
                containerId !== undefined && containerOf(id) === containerId,
        ),
    );

    return sortableKeyboardCoordinates(event, {
        ...args,
        context: { ...args.context, droppableRects: rects },
    });
};

/**
 * 取っ手のドラッグでの並び替えのセンサー(マウスと、取っ手にフォーカスして Space → 矢印 → Space のキーボード)。
 * 取っ手のクリックでドラッグが始まらないよう、少し動いてから始める(dnd-kit の推奨)。
 */
export function useSortSensors() {
    return useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sameListKeyboardCoordinates,
        }),
    );
}
