import { Head, Link } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordAttachmentList } from '@/components/RecordAttachmentList';
import { RecordAddonActions } from '@/components/Records/RecordAddonActions';
import { RecordAddonBadges } from '@/components/Records/RecordAddonBadges';
import { RecordApplyButton } from '@/components/Records/RecordApplyButton';
import { RecordCommentsPane } from '@/components/Records/RecordCommentsPane';
import { RecordDetailTabs } from '@/components/Records/RecordDetailTabs';
import { RecordHoldNotice } from '@/components/Records/RecordHoldNotice';
import { RecordRelatedDialog } from '@/components/RecordRelatedDialog';
import { RecordRevisionList } from '@/components/RecordRevisionList';
import { RecordShowLayout } from '@/components/RecordShowLayout';
import { RecordShowSection } from '@/components/RecordShowSection';
import { RecordSidePanel } from '@/components/RecordSidePanel';
import { Button } from '@/components/ui/button';
import { useRecordRelated } from '@/hooks/use-record-related';
import { useRecordSidePanel } from '@/hooks/use-record-side-panel';
import { deleteRecordDescription } from '@/lib/recordShow';
import { recordHeading } from '@/lib/recordValues';
import {
    create,
    destroy,
    edit,
    index,
    show,
    store,
} from '@/routes/records/data';
import attachments from '@/routes/records/data/attachments';
import type {
    RecordApplyEntry,
    RecordDetailCan,
    RecordHeadingGroup,
    RecordHold,
    RecordModuleDefinition,
    RecordPanelContent,
    RecordRelatedProps,
    RecordShowAddons,
    RecordShowDetail,
    RecordShowPanelProps,
} from '@/types';

type Props = RecordShowPanelProps &
    RecordRelatedProps & {
        module: RecordModuleDefinition;
        /** 見出しのまとまり(詳細タブの項目を見出しごとに畳める) */
        headings: RecordHeadingGroup[];
        record: RecordShowDetail;
        /** 権限の判定だけ(持ち主の有無は hold = 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1 節) */
        can: RecordDetailCan;
        hold: RecordHold | null;
        /** 申請できる入口(空 = 「申請する」を出さない。出し方 = 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 2 節) */
        applyEntries: RecordApplyEntry[];
        addons: RecordShowAddons; // 操作・札・注(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)
    };

type ShowActionsProps = Pick<
    Props,
    'module' | 'record' | 'can' | 'hold' | 'applyEntries' | 'addons'
> & { heading: string };

/** 「編集」(持ち主があれば押せない形) */
function EditButton({
    route,
    held,
}: {
    route: { module: string; record: number };
    held: boolean;
}) {
    return held ? (
        <Button disabled>編集</Button>
    ) : (
        <Button asChild>
            <Link href={edit(route)}>編集</Link>
        </Button>
    );
}

/**
 * 見出しの右の操作(詳細の画面/README.md の 5.1 節): 権限が無ければ出さず、持ち主(申請済み)があれば押せない形で出す(理由は見出しの下の 1 行)。
 */
function ShowActions(props: ShowActionsProps) {
    const { module, record, can, hold, applyEntries, addons, heading } = props;
    const held = hold !== null;
    const hasDetails = module.fields.some((field) => field.type === 'details');
    const route = { module: module.code, record: record.id };

    return (
        <div className="flex items-center gap-2">
            {can.update && <EditButton route={route} held={held} />}
            <RecordAddonActions actions={addons.actions} held={held} />
            {can.copy && (
                <CopyButton moduleCode={module.code} recordId={record.id} />
            )}
            <RecordApplyButton
                entries={applyEntries}
                held={held}
                heading={heading}
            />
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

/** 「複製」(押せば登録の画面に元の値が入る。申請済みも押せる = レコードの共通機能/複製.md の 1 節) */
function CopyButton({
    moduleCode,
    recordId,
}: {
    moduleCode: string;
    recordId: number;
}) {
    return (
        <Button variant="outline" asChild>
            <Link href={create.url(moduleCode, { query: { copy: recordId } })}>
                複製
            </Link>
        </Button>
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

/** 行の詳細の URL(関連レコードの表のリンク) */
function recordHref(moduleCode: string, recordId: number): string {
    return show.url({ module: moduleCode, record: recordId });
}

/**
 * 左の詳細の面(タブ)と、関連レコードの「新規」のダイアログ(関連レコード.md の 3・4 節)。
 */
function ShowMain({ props, heading }: { props: Props; heading: string }) {
    const { module, record } = props;
    const related = useRecordRelated(
        props.relatedRecords,
        props.relatedRecordRows,
    );

    return (
        <section
            aria-label={`${heading}の値`}
            className="rounded-xl border bg-card px-5 pt-1 pb-5 text-card-foreground"
        >
            <RecordDetailTabs
                module={module}
                headings={props.headings}
                record={record}
                fieldNotes={props.addons.fieldNotes}
                related={{ ...related, rowHref: recordHref }}
            />
            <RecordRelatedDialog
                open={related.dialog.open}
                form={related.dialog.form}
                status={related.dialog.status}
                recordId={record.id}
                ownerText={`${module.name}「${heading}」`}
                storeAction={(moduleCode) => store(moduleCode)}
                onClose={related.dialog.close}
            />
        </section>
    );
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
                    main={<ShowMain props={props} heading={heading} />}
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
