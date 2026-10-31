import { FlowVersionTable } from '@/components/Workflow/FlowVersionTable';
import { VersionAddDialog } from '@/components/Workflow/VersionAddDialog';
import {
    versionAddBlockedText,
    versionStartBounds,
} from '@/lib/Workflow/flowVersionAdd';
import type { WorkflowFlowVersionRow } from '@/types';

/**
 * 版のタブ(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.2 節): 見出し「版の一覧」と「版を追加」
 * (最後の版の開始日が今日 + 1 年以後なら、ボタンの代わりに追加できない理由の文)、版の表、期間の注意の 1 行。
 */
export function FlowVersionsTab({
    flowCode,
    flowName,
    versions,
    today,
}: {
    flowCode: string;
    flowName: string;
    versions: WorkflowFlowVersionRow[];
    /** サーバーの今日(YYYY-MM-DD) */
    today: string;
}) {
    const last = versions[0];
    const canAdd = versionStartBounds(today, last.startDate).canAdd;

    return (
        <section
            aria-labelledby="versions-heading"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex items-center justify-between gap-4">
                <h2 id="versions-heading" className="text-[15px] font-semibold">
                    版の一覧
                </h2>
                {canAdd ? (
                    <VersionAddDialog
                        flowCode={flowCode}
                        flowName={flowName}
                        last={last}
                        today={today}
                    />
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {versionAddBlockedText(last.number)}
                    </p>
                )}
            </div>
            <FlowVersionTable flowCode={flowCode} versions={versions} />
            <p className="text-sm text-muted-foreground">
                版の期間に重複と空白は設定できません。申請は、申請基準日を含む有効な版で行われます。
            </p>
        </section>
    );
}
