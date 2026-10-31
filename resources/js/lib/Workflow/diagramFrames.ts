import type { Node } from '@xyflow/react';
import type {
    DiagramInputNode,
    PlaceholderNodeData,
    StepNodeData,
} from '@/lib/Workflow/diagramTypes';
import { personName } from '@/lib/Workflow/workflowLabels';

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
/** 枠の見出しの高さ(px。左に種類と名前、右に短い文の 1 行) */
export const FRAME_HEADER_HEIGHT = 40;
const FRAME = {
    padding: 16,
    header: FRAME_HEADER_HEIGHT,
    gap: 16,
    consensus: 44,
    minWidth: 288,
    emptyHeight: 32,
};

/** 枠の見出しの字の大きさ(px。左の種類と名前 = text-sm、右の短い文 = text-xs)と、左右の間 */
const HEADER_TEXT = { title: 14, subtitle: 12, gap: 12 };
/** 見出しの左の「並列承認「」」の種類の語と括弧の字数 */
const HEADER_TITLE_EXTRA = 6;

/**
 * 枠の見出しが切れずに入る幅(px。左の「{種類}「{名前}」」と右の短い文を 1 行に並べる。字数 × 字の大きさで見積もる =
 * 全角の字の幅。半角の字は多めに見積もる)。
 */
export function frameHeaderWidth(node: DiagramInputNode): number {
    const title = (node.name.length + HEADER_TITLE_EXTRA) * HEADER_TEXT.title;
    const subtitle =
        node.subtitle === null
            ? 0
            : HEADER_TEXT.gap + node.subtitle.length * HEADER_TEXT.subtitle;

    return FRAME.padding * 2 + title + subtitle;
}

/** 並列承認の枠の中の箱で、人がまだ選ばれていないときの題 */
export const UNPICKED_TITLE = '未選択';

/**
 * 並列承認の枠の中の箱の題(人の名前を「、」で。自分なら「(自分)」を添える。人がまだ選ばれていなければ「未選択」。
 * 番号の題「関係部署の合議 承認 1」は出さない = 図と意匠とE2E.md 8 節。ユーザーの決定 2026-11-01)。
 */
export function councilBoxTitle(node: DiagramInputNode): string {
    if (node.people.length === 0) {
        return UNPICKED_TITLE;
    }

    return node.people
        .map((person) => `${personName(person)}${person.isMe ? '(自分)' : ''}`)
        .join('、');
}

/**
 * 枠の見出しの文(左 =「{種類}「{名前}」」、右 = 短い文)。状態の札は出さない(状態は子の箱の札と縁で読む = 図と意匠とE2E.md 8 節)。
 * kindLabel = 枠の種類の語(WorkflowDiagram.tsx の FRAME_KIND_LABELS)。
 */
export function frameHeaderTexts(
    node: DiagramInputNode,
    kindLabel: string,
): { title: string; subtitle: string | null } {
    return { title: `${kindLabel}「${node.name}」`, subtitle: node.subtitle };
}

/** 枠の大きさ(子の数と並べ方で決まる。幅は見出しが切れずに入る幅 headerWidth 以上) */
export function frameSize(
    row: boolean,
    hasConsensus: boolean,
    childCount: number,
    headerWidth = 0,
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
        width: Math.max(
            childrenWidth + FRAME.padding * 2,
            FRAME.minWidth,
            headerWidth,
        ),
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
            data: { node: child, inCouncil: node.kind === 'parallel' },
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
