import { Head, Link } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordAttachmentList } from '@/components/RecordAttachmentList';
import { RecordAddonActions } from '@/components/Records/RecordAddonActions';
import { RecordAddonBadges } from '@/components/Records/RecordAddonBadges';
import { RecordCommentsPane } from '@/components/Records/RecordCommentsPane';
import { RecordDetailTabs } from '@/components/Records/RecordDetailTabs';
import { RecordHoldNotice } from '@/components/Records/RecordHoldNotice';
import { RecordRevisionList } from '@/components/RecordRevisionList';
import { RecordShowLayout } from '@/components/RecordShowLayout';
import { RecordShowSection } from '@/components/RecordShowSection';
import { RecordSidePanel } from '@/components/RecordSidePanel';
import { Button } from '@/components/ui/button';
import { useRecordSidePanel } from '@/hooks/use-record-side-panel';
import { deleteRecordDescription } from '@/lib/recordShow';
import { recordHeading } from '@/lib/recordValues';
import { destroy, edit, index, show } from '@/routes/records/data';
import attachments from '@/routes/records/data/attachments';
import type {
    RecordDetail,
    RecordDetailCan,
    RecordHold,
    RecordModuleDefinition,
    RecordPanelContent,
    RecordShowAddons,
    RecordShowPanelProps,
} from '@/types';

type Props = RecordShowPanelProps & {
    module: RecordModuleDefinition;
    record: RecordDetail;
    /** 権限の判定だけ(持ち主の有無は hold = 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1 節) */
    can: RecordDetailCan;
    hold: RecordHold | null;
    addons: RecordShowAddons; // 操作・札・注(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)
};

/**
 * 見出しの右の操作(詳細の画面/README.md の 5.1 節): 権限が無ければ出さず、持ち主(申請中)があれば押せない形で出す(理由は見出しの下の 1 行)。
 */
function ShowActions({
    module,
    record,
    can,
    hold,
    addons,
    heading,
}: Omit<Props, 'panel' | 'panelContent' | 'sections'> & { heading: string }) {
    const held = hold !== null;
    const hasDetails = module.fields.some((field) => field.type === 'details');
    const route = { module: module.code, record: record.id };

    return (
        <div className="flex items-center gap-2">
            {can.update &&
                (held ? (
                    <Button disabled>編集</Button>
                ) : (
                    <Button asChild>
                        <Link href={edit(route)}>編集</Link>
                    </Button>
                ))}
            <RecordAddonActions actions={addons.actions} held={held} />
            {can.delete &&
                (held ? (
                    <Button variant="destructive" disabled>
                        削除
                    </Button>
                ) : (
                    <ConfirmDeleteDialog
                        triggerVariant="destructive"
                        title="レコードの削除"
                        description={deleteRecordDescription(
                            heading,
                            hasDetails,
                        )}
                        action={destroy(route)}
                    />
                ))}
        </div>
    );
}

/** 見出しと、後ろのモジュールが足す札(業務の画面.md の 2.5) */
function ShowHeading({
    heading,
    badges,
}: {
    heading: string;
    badges: RecordShowAddons['badges'];
}) {
    return (
        <div className="flex items-center gap-3">
            <h1 className="text-2xl font-semibold">{heading}</h1>
            <RecordAddonBadges badges={badges} />
        </div>
    );
}

/**
 * 開いた欄の中身(欄の種類ごと)。開く・追加・削除の URL はここで作って部品に渡す(部品はルートの名前を知らない = 7 節)。
 */
function PanelBody({
    content,
    props,
    panel,
}: {
    content: RecordPanelContent | null;
    props: Props;
    panel: ReturnType<typeof useRecordSidePanel>;
}) {
    const route = { module: props.module.code, record: props.record.id };

    switch (content?.kind) {
        case undefined:
            return <p className="text-muted-foreground">読み込んでいます</p>;
        case 'attachments':
            return (
                <RecordAttachmentList
                    {...content}
                    canAttach={props.can.attach}
                    held={props.hold !== null}
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

export default function Show(props: Props) {
    const { module, record, hold, addons } = props;
    const heading = recordHeading(module, record);
    const items = props.panel?.items ?? [];
    const panel = useRecordSidePanel(items, props.panelContent);

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <ShowHeading heading={heading} badges={addons.badges} />
                    <ShowActions {...props} heading={heading} />
                </div>
                {hold !== null && <RecordHoldNotice hold={hold} />}
                <RecordShowLayout
                    open={panel.openKey !== null}
                    main={
                        <section
                            aria-label={`${heading}の値`}
                            className="rounded-xl border bg-card px-5 pt-1 pb-5 text-card-foreground"
                        >
                            <RecordDetailTabs
                                module={module}
                                record={record}
                                fieldNotes={addons.fieldNotes}
                            />
                        </section>
                    }
                    side={
                        <RecordSidePanel
                            items={items}
                            openKey={panel.openKey}
                            onToggle={panel.toggle}
                            onClose={panel.close}
                        >
                            <PanelBody
                                content={panel.content}
                                props={props}
                                panel={panel}
                            />
                        </RecordSidePanel>
                    }
                />
            </div>
        </>
    );
}

Show.layout = (props: Props) => ({
    breadcrumbs: [
        { title: props.module.name, href: index(props.module.code) },
        {
            title: recordHeading(props.module, props.record),
            href: show({ module: props.module.code, record: props.record.id }),
        },
    ],
});
