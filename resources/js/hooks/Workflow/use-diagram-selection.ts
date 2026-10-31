import { createContext, useContext } from 'react';
import type { KeyboardEvent } from 'react';
import type { Node } from '@xyflow/react';
import type { DiagramInputNode } from '@/lib/Workflow/diagramTypes';

/**
 * 定義の図で選べるステップ(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節)。
 * 押すとステップを選ぶ。案件の図は渡さず、押せない。
 */
export type DiagramSelection = {
    /** 選んだステップのコード */
    code: string;
    onSelect: (code: string) => void;
};

export const SelectionContext = createContext<DiagramSelection | null>(null);

/** 選べる箱の属性 */
type SelectionProps = {
    role?: 'button';
    tabIndex?: number;
    'aria-pressed'?: boolean;
    className?: string;
    onKeyDown?: (event: KeyboardEvent) => void;
};

/**
 * 箱の選択の属性(選べる図のときだけ。ボタンの役割・aria-pressed・キーボード)と、選んだかどうか。クリックは React Flow の
 * onNodeClick が受ける(箱のラッパーはクリックの処理が無いとポインターを受けないため)。
 */
export function useNodeSelection(node: DiagramInputNode): {
    isSelected: boolean;
    props: SelectionProps;
} {
    const selection = useContext(SelectionContext);

    if (selection === null || node.code === null) {
        return { isSelected: false, props: {} };
    }

    const code = node.code;
    const isSelected = selection.code === code;

    return {
        isSelected,
        props: {
            role: 'button',
            tabIndex: 0,
            'aria-pressed': isSelected,
            className: 'cursor-pointer outline-none focus-visible:ring-2',
            onKeyDown: (event: KeyboardEvent) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selection.onSelect(code);
                }
            },
        },
    };
}

/**
 * 押した React Flow の箱が表すステップのコード(仮の札・終了の印は無い)。
 */
export function codeOfNode(node: Node): string | null {
    const data: unknown = node.data;

    if (typeof data === 'object' && data !== null && 'node' in data) {
        return (data.node as DiagramInputNode).code;
    }

    return null;
}
