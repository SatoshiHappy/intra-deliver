import { Head } from '@inertiajs/react';
import { FlowDefinitionTable } from '@/components/Workflow/FlowDefinitionTable';
import { index } from '@/routes/workflow/admin/flows';
import type { WorkflowAdminFlowIndexProps } from '@/types';

/**
 * フロー定義の一覧(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 2 節): 見出しと 1 行、フロー定義の表(全 N 件。
 * ページングしない)。行の「詳細」から詳細(フロー図・版・設定)へ移る。
 */
export default function FlowIndex({ flows }: WorkflowAdminFlowIndexProps) {
    return (
        <>
            <Head title="フロー定義" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold">フロー定義</h1>
                    <p className="text-sm text-muted-foreground">
                        フロー定義の一覧です。版と参照者を確認し、詳細画面から編集できます。
                    </p>
                </div>
                <section
                    aria-labelledby="flow-definitions-heading"
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <div className="flex items-center justify-between gap-4">
                        <h2
                            id="flow-definitions-heading"
                            className="text-[15px] font-semibold"
                        >
                            フロー定義の一覧
                        </h2>
                        <span className="text-sm text-muted-foreground">
                            全{' '}
                            <span className="font-num tabular-nums">
                                {flows.length}
                            </span>{' '}
                            件
                        </span>
                    </div>
                    <FlowDefinitionTable flows={flows} />
                </section>
            </div>
        </>
    );
}

FlowIndex.layout = {
    breadcrumbs: [{ title: 'フロー定義', href: index() }],
};
