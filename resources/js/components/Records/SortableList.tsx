import {
    closestCenter,
    DndContext,
    DragOverlay,
    MeasuringStrategy,
} from '@dnd-kit/core';
import type {
    CollisionDetection,
    DragCancelEvent,
    DragEndEvent,
    DragOverEvent,
    DragStartEvent,
    UniqueIdentifier,
} from '@dnd-kit/core';
import {
    SortableContext,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import type { SortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useSortSensors } from '@/hooks/Records/use-sort-sensors';
import {
    sortAnnouncements,
    SORT_SCREEN_READER_INSTRUCTIONS,
} from '@/lib/Records/sortOrder';
import { cn } from '@/lib/utils';

type AreaProps = {
    /** DndContext の id(画面の中で一意) */
    id: string;
    /** 行の ID(と、落とす先の見出しの ID)→ 読み上げの名前 */
    nameOf: (id: string) => string;
    /** 当たりの判定(省略 = 行の中心の近さ。並びが 1 つのとき) */
    collisionDetection?: CollisionDetection;
    /** つかんだ行の写し(省略 = 写しを使わず、つかんだ行そのものが動く) */
    overlay?: ReactNode;
    onDragStart?: (event: DragStartEvent) => void;
    onDragOver?: (event: DragOverEvent) => void;
    onDragEnd: (event: DragEndEvent) => void;
    onDragCancel?: (event: DragCancelEvent) => void;
    children: ReactNode;
};

/**
 * 取っ手のドラッグで並べ替える区域の外枠(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.3 の項目の表と、モジュール分類/画面と検査.md 4.4 の分類の木):
 * DndContext・センサー(マウスと、取っ手で Space → 矢印 → Space のキーボード)・当たりの判定・写し(DragOverlay)・読み上げを持つ。並びは
 * {@link SortableRows} で中に置く(1 つの外枠に並びを 2 つ以上置くと、行は並びをまたいで落とせる = 当たりの判定で候補を絞る)。
 * 落とす先の位置は、つかんだ後もいつも測り直す(MeasuringStrategy.Always。つかんですぐに矢印を押しても、測る前の位置で動かない)。
 */
export function SortableArea({
    id,
    nameOf,
    collisionDetection = closestCenter,
    overlay,
    onDragStart,
    onDragOver,
    onDragEnd,
    onDragCancel,
    children,
}: AreaProps) {
    const sensors = useSortSensors();

    return (
        <DndContext
            id={id}
            sensors={sensors}
            collisionDetection={collisionDetection}
            measuring={{ droppable: { strategy: MeasuringStrategy.Always } }}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDragEnd={onDragEnd}
            onDragCancel={onDragCancel}
            accessibility={{
                announcements: sortAnnouncements((item) =>
                    nameOf(String(item)),
                ),
                screenReaderInstructions: SORT_SCREEN_READER_INSTRUCTIONS,
            }}
        >
            {children}
            {overlay !== undefined && (
                <DragOverlay dropAnimation={null}>{overlay}</DragOverlay>
            )}
        </DndContext>
    );
}

/**
 * 縦の並びの並べ方。つかんだ行と落とす先が同じ並びにあるときだけ行をずらす(ほかの並びから来た行・ほかの並びの上にあるときは隙間を開けない)。
 * 並びが 1 つなら verticalListSortingStrategy と同じ。
 */
const sameListStrategy: SortingStrategy = (args) =>
    args.activeIndex < 0 || args.overIndex < 0
        ? null
        : verticalListSortingStrategy(args);

/**
 * 外枠({@link SortableArea})の中の 1 つの並び。id = 並びの ID(行の useSortable の containerId。キーボードの矢印はこの並びの行だけを辿る)。
 * 行は {@link useSortableRow} と {@link SortHandle} で描く。disabled の間(送っている間など)はドラッグを受けない。
 */
export function SortableRows({
    id,
    ids,
    disabled,
    children,
}: {
    id: string;
    /** 行の ID(今の並び) */
    ids: string[];
    disabled: boolean;
    children: ReactNode;
}) {
    return (
        <SortableContext
            id={id}
            items={ids}
            strategy={sameListStrategy}
            disabled={disabled}
        >
            {children}
        </SortableContext>
    );
}

/**
 * 同じ並びの中のドロップの [元の添字, 先の添字](ids = その並びの行の ID)。動かないとき・どちらかが並びに無いとき
 * (ドラッグの間に props が入れ替わって行が消えた、ほかの並びの上で落とした)は null。
 */
export function moveWithin(
    ids: string[],
    active: UniqueIdentifier,
    over: UniqueIdentifier | null,
): [number, number] | null {
    if (over === null || active === over) {
        return null;
    }

    const from = ids.indexOf(String(active));
    const to = ids.indexOf(String(over));

    return from >= 0 && to >= 0 ? [from, to] : null;
}

/**
 * 並びの 1 行({@link SortableRows} の中で使う)。行の要素に付ける ref・style・つかんでいる間の class と、取っ手に渡す値を返す。
 * 写し(DragOverlay)を使う外枠では、呼び手が rowClassName の代わりに handle.isDragging で元の行の見た目を決める。
 */
export function useSortableRow(id: string) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });
    const style: CSSProperties = {
        transform: CSS.Translate.toString(transform),
        transition,
    };

    return {
        setNodeRef,
        style,
        rowClassName: cn(
            isDragging && 'relative z-10 bg-card shadow-md ring-1 ring-ring',
        ),
        handle: {
            ref: setActivatorNodeRef,
            attributes,
            listeners,
            isDragging,
        },
    };
}

/**
 * 行の取っ手(lucide の grip-vertical。取っ手だけが並び替えを始める)。label = 読み上げの名前。
 */
export function SortHandle({
    handle,
    label,
}: {
    handle: ReturnType<typeof useSortableRow>['handle'];
    label: string;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            ref={handle.ref}
            {...handle.attributes}
            {...handle.listeners}
            aria-label={label}
            className={cn(
                'size-8 shrink-0 touch-none text-muted-foreground',
                handle.isDragging ? 'cursor-grabbing' : 'cursor-grab',
            )}
        >
            <GripVertical />
        </Button>
    );
}
