import { ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { flowHeadlineText } from '@/lib/Workflow/flowDefinitionTexts';
import { flowAuthzLinkOf } from '@/lib/Workflow/personLinks';
import type { WorkflowAdminFlowShowProps } from '@/types';

/**
 * 詳細の見出し(フロー名の h1)と、下の 1 行(業務モジュールと現在の版。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.1 節)。見出しの右に「認可設定で見る」(新しいタブ。
 * 設計/ワークフロー/申請の周辺/メニューと導線.md の 2.2。認可設定の execute を許されない人には出さない)。
 */
export function FlowHeading({
    flow,
    versions,
    links,
}: Pick<WorkflowAdminFlowShowProps, 'flow' | 'versions' | 'links'>) {
    const authz = flowAuthzLinkOf(links);

    return (
        <div className="flex items-start justify-between gap-4">
            <div className="flex min-w-0 flex-col gap-1">
                <h1 className="text-2xl font-semibold wrap-anywhere">
                    {flow.name}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {flowHeadlineText(flow.moduleName, versions)}
                </p>
            </div>
            {authz !== null && (
                <Button asChild variant="outline" size="sm">
                    <a
                        href={authz.href}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {authz.label}
                        <ExternalLink aria-hidden="true" />
                    </a>
                </Button>
            )}
        </div>
    );
}
