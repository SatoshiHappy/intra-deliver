import { RecordAttachmentList } from '@/components/RecordAttachmentList';
import { RecordCommentsPane } from '@/components/RecordCommentsPane';
import { RecordRevisionList } from '@/components/RecordRevisionList';
import { RecordShowSection } from '@/components/RecordShowSection';
import { RecordSidePanel } from '@/components/RecordSidePanel';
import type { useRecordSidePanel } from '@/hooks/use-record-side-panel';
import {
    ATTACHMENT_NOTICE,
    COMMENT_NOTICE,
} from '@/lib/Workflow/caseShowTexts';
import attachments from '@/routes/records/data/attachments';
import type { RecordPanelContent, RecordShowPanelProps } from '@/types';

type Panel = ReturnType<typeof useRecordSidePanel>;
type Route = { module: string; record: number };

/**
 * 開いた欄の中身(欄の種類ごと。レコードの詳細と同じ部品)。申請の詳細だけの違い(docs の 設計/業務データの入れ物/レコードの共通機能/
 * 詳細の画面/申請の詳細.md の 4.1): 添付は表示だけ(追加・削除を出さず、先頭は 1 行だけ)、コメントの先頭に 1 行。
 */
function PanelBody({
    content,
    route,
    panel,
}: {
    content: RecordPanelContent | null;
    route: Route;
    panel: Panel;
}) {
    switch (content?.kind) {
        case undefined:
            return <p className="text-muted-foreground">読み込んでいます</p>;
        case 'attachments':
            return (
                <RecordAttachmentList
                    {...content}
                    notice={ATTACHMENT_NOTICE}
                    canAttach={false}
                    held={false}
                    uploadUrl={attachments.store(route).url}
                    deleteActionOf={(row) =>
                        attachments.destroy({ ...route, attachment: row.id })
                    }
                    loading={panel.loading}
                    onMore={panel.more}
                />
            );
        case 'comments':
            return (
                <RecordCommentsPane
                    content={content}
                    route={route}
                    notice={COMMENT_NOTICE}
                    loading={panel.loading}
                    onMore={panel.more}
                />
            );
        case 'revisions':
            return (
                <RecordRevisionList
                    {...content}
                    loading={panel.loading}
                    onMore={panel.more}
                />
            );
        case 'section':
            return <RecordShowSection content={content.content} />;
    }
}

/**
 * 申請の詳細のサイドパネル(帯と開いた欄。レコードの詳細と同じ部品)。route = 申請書のレコードの業務モジュールとレコードの id。
 */
export function CaseSidePanel({
    panelProps,
    route,
    panel,
}: {
    panelProps: Pick<RecordShowPanelProps, 'panel' | 'panelContent'>;
    route: Route;
    panel: Panel;
}) {
    return (
        <RecordSidePanel
            items={panelProps.panel?.items ?? []}
            openKey={panel.openKey}
            onToggle={panel.toggle}
            onClose={panel.close}
        >
            <PanelBody content={panel.content} route={route} panel={panel} />
        </RecordSidePanel>
    );
}
