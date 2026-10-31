import { flowHeadlineText } from '@/lib/Workflow/flowDefinitionTexts';
import type { WorkflowAdminFlowShowProps } from '@/types';

/**
 * 詳細の見出し(フロー名の h1)と、下の 1 行(業務モジュールと現在の版。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.1 節)。
 */
export function FlowHeading({
    flow,
    versions,
}: Pick<WorkflowAdminFlowShowProps, 'flow' | 'versions'>) {
    return (
        <div className="flex flex-col gap-1">
            <h1 className="text-2xl font-semibold wrap-anywhere">
                {flow.name}
            </h1>
            <p className="text-sm text-muted-foreground">
                {flowHeadlineText(flow.moduleName, versions)}
            </p>
        </div>
    );
}
