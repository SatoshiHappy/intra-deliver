import { Head } from '@inertiajs/react';
import { FlowHeading } from '@/components/Workflow/FlowHeading';
import { FlowTabs } from '@/components/Workflow/FlowTabs';
import { FlowTabPanel } from '@/components/Workflow/FlowTabPanel';
import { index, show } from '@/routes/workflow/admin/flows';
import type { WorkflowAdminFlowShowProps } from '@/types';

type Props = WorkflowAdminFlowShowProps;

/**
 * フロー定義の詳細(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3 節): 見出し = フロー名、下に業務モジュールと現在の版の 1 行、
 * タブ(フロー図・版・参照者。リンクの並びで aria-current)と、タブごとの面。選んだ版・タブ・ステップは URL のクエリに残り、
 * タブの切替は部分の再読み込み。
 */
export default function FlowShow(props: Props) {
    const { flow, versions, selectedVersion, tab, selectedNode } = props;

    return (
        <>
            <Head title={flow.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <FlowHeading flow={flow} versions={versions} />
                <FlowTabs
                    flowCode={flow.code}
                    version={selectedVersion}
                    node={selectedNode.code}
                    current={tab}
                />
                <FlowTabPanel {...props} />
            </div>
        </>
    );
}

FlowShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'フロー定義', href: index() },
        { title: props.flow.name, href: show(props.flow.code) },
    ],
});
