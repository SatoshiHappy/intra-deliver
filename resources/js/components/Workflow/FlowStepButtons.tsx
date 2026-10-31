import { Button } from '@/components/ui/button';
import type { WorkflowDiagramNode } from '@/types';

/**
 * 図の下のステップの名前のボタンの並び(キーボードでも選べる。並び = 写した並びで、図と同じ選択を表す。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節)。選んだステップは aria-pressed。
 */
export function FlowStepButtons({
    nodes,
    selectedCode,
    onSelect,
}: {
    nodes: WorkflowDiagramNode[];
    selectedCode: string;
    onSelect: (code: string) => void;
}) {
    return (
        <div
            role="group"
            aria-label="ステップの選択"
            className="flex flex-wrap gap-2"
        >
            {nodes.map((node) =>
                node.code === null ? null : (
                    <Button
                        key={node.id}
                        type="button"
                        size="sm"
                        variant={
                            node.code === selectedCode ? 'secondary' : 'outline'
                        }
                        aria-pressed={node.code === selectedCode}
                        onClick={() => onSelect(node.code ?? '')}
                    >
                        {node.name}
                    </Button>
                ),
            )}
        </div>
    );
}
