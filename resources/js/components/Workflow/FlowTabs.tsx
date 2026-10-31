import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { FLOW_TABS } from '@/lib/Workflow/flowDefinitionTexts';
import { flowShowUrl, propsOfTab } from '@/lib/Workflow/flowDefinitionUrl';
import type { WorkflowFlowDefinitionTab } from '@/types';

/**
 * 詳細のタブ「フロー図」「版」「参照者」(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.1 節。
 * リンクの並びで、今のタブに aria-current。部分の再読み込みで URL のクエリが変わる)。選んだ版とステップは移った先にも引き継ぐ。
 */
export function FlowTabs({
    flowCode,
    version,
    node,
    current,
}: {
    flowCode: string;
    version: number;
    node: string;
    current: WorkflowFlowDefinitionTab;
}) {
    return (
        <nav aria-label="フロー定義のタブ" className="flex items-center gap-1">
            {FLOW_TABS.map(({ tab, label }) => (
                <Link
                    key={tab}
                    href={flowShowUrl(flowCode, { version, tab, node })}
                    only={propsOfTab(tab)}
                    preserveState
                    preserveScroll
                    aria-current={tab === current ? 'page' : undefined}
                    className={cn(
                        'inline-flex h-9 items-center rounded-md px-3 text-sm font-medium',
                        'hover:bg-accent hover:text-accent-foreground',
                        tab === current &&
                            'bg-secondary text-secondary-foreground',
                    )}
                >
                    {label}
                </Link>
            ))}
        </nav>
    );
}
