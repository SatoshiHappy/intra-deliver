import type { Node } from '@xyflow/react';
import type {
    DiagramInputNode,
    PlaceholderNodeData,
    StepNodeData,
} from '@/lib/Workflow/diagramTypes';

/**
 * 横配置・縦配置の枠の中の並べ方(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節): 子(案件の展開の行、定義の図は仮の札)を、
 * 横配置は縦一列、縦配置は横一列に並べる。枠の大きさは子の数と並べ方で決まる。
 */

/** 枠の中の 1 つ分の箱(案件の展開の行か、定義の仮の札) */
export type ChildSlot =
    | { id: string; type: 'step'; data: StepNodeData }
    | { id: string; type: 'placeholder'; data: PlaceholderNodeData };

/** 枠の子のノード(枠の左上からの相対の座標) */
export type ChildNode =
    | Node<StepNodeData, 'step'>
    | Node<PlaceholderNodeData, 'placeholder'>;

/** 子の箱の大きさ(px。札を人の行の下に置くので、上の段の箱より 1 行分高い) */
const CHILD_SIZE = { width: 176, height: 96 };
/** 枠の見出しの高さ(px。左に種類と名前・状態の札、右に短い文の 1 行) */
export const FRAME_HEADER_HEIGHT = 40;
const FRAME = {
    padding: 16,
    header: FRAME_HEADER_HEIGHT,
    gap: 16,
    consensus: 44,
    minWidth: 288,
    emptyHeight: 32,
};

/** 枠の大きさ(子の数と並べ方で決まる) */
export function frameSize(
    row: boolean,
    hasConsensus: boolean,
    childCount: number,
) {
    const childrenWidth = row
        ? childCount * CHILD_SIZE.width +
          Math.max(childCount - 1, 0) * FRAME.gap
        : CHILD_SIZE.width;
    const childrenHeight =
        childCount === 0
            ? FRAME.emptyHeight
            : row
              ? CHILD_SIZE.height
              : childCount * CHILD_SIZE.height + (childCount - 1) * FRAME.gap;

    return {
        width: Math.max(childrenWidth + FRAME.padding * 2, FRAME.minWidth),
        height:
            FRAME.header +
            childrenHeight +
            (hasConsensus ? FRAME.consensus : 0) +
            FRAME.padding,
    };
}

/**
 * 枠の中の箱の並び(案件の展開の行、定義の仮の札の順)。
 */
export function slotsOf(
    node: DiagramInputNode,
    kids: DiagramInputNode[],
): ChildSlot[] {
    return [
        ...kids.map((child): ChildSlot => ({
            id: String(child.id),
            type: 'step',
            data: { node: child },
        })),
        ...node.placeholders.map((placeholder, index): ChildSlot => ({
            id: `${node.id}-slot-${index}`,
            type: 'placeholder',
            data: placeholder,
        })),
    ];
}

/**
 * 枠の子(枠の左上からの相対の座標)。子の並び全体を枠の幅の中央に置く(上下の辺が子の並びの中央を通る)。
 */
export function placeChildren(
    parent: DiagramInputNode,
    slots: ChildSlot[],
    frameWidth: number,
): ChildNode[] {
    const row = parent.kind === 'parallel';
    const rowWidth = row
        ? slots.length * CHILD_SIZE.width +
          Math.max(slots.length - 1, 0) * FRAME.gap
        : CHILD_SIZE.width;
    const left = (frameWidth - rowWidth) / 2;

    return slots.map((slot, index) => ({
        ...slot,
        parentId: String(parent.id),
        extent: 'parent',
        position: row
            ? {
                  x: left + index * (CHILD_SIZE.width + FRAME.gap),
                  y: FRAME.header,
              }
            : {
                  x: left,
                  y: FRAME.header + index * (CHILD_SIZE.height + FRAME.gap),
              },
        width: CHILD_SIZE.width,
        height: CHILD_SIZE.height,
    }));
}
