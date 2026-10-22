import { Download, FileText, Plus } from 'lucide-react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordAttachmentPreview } from '@/components/RecordAttachmentPreview';
import { RecordPanelMore } from '@/components/RecordPanelMore';
import { SendingProgress } from '@/components/SendingProgress';
import { Button } from '@/components/ui/button';
import { useAttachmentUpload } from '@/hooks/use-attachment-upload';
import { fileSizeText, personText } from '@/lib/recordShow';
import { formatDate, formatDatetime } from '@/lib/recordValues';
import type { RecordAttachmentRow } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

type Props = {
    rows: RecordAttachmentRow[];
    hasMore: boolean;
    /** 使用しない業務モジュール・申請中の 1 行 */
    notice: string | null;
    /** 「ファイルを追加」の案内の 1 行 */
    guide: string;
    /** 追加と削除を出すか(業務モジュールの update かつ使用する) */
    canAttach: boolean;
    /** 申請中(追加と削除を押せない形にする) */
    held: boolean;
    uploadUrl: string;
    deleteActionOf: (row: RecordAttachmentRow) => RouteDefinition<'delete'>;
    loading: boolean;
    onMore: (beforeId: number) => void;
};

type ItemProps = Pick<Props, 'canAttach' | 'held'> & {
    row: RecordAttachmentRow;
    deleteAction: RouteDefinition<'delete'>;
};

/** 行の削除(申請中は押せない形。押せない間は確かめの Dialog を置かない) */
function DeleteAttachment({ row, held, deleteAction }: ItemProps) {
    return held ? (
        <Button
            variant="ghost"
            size="sm"
            disabled
            aria-label={`${row.name}を削除`}
        >
            削除
        </Button>
    ) : (
        <ConfirmDeleteDialog
            triggerLabel={`${row.name}を削除`}
            title="添付ファイルの削除"
            description={`${row.name}を削除しますか。削除したファイルは、更新履歴から表示できます。`}
            action={deleteAction}
            preserveScroll
        />
    );
}

function AttachmentActions(props: ItemProps) {
    const { row } = props;

    return (
        <span className="flex basis-full flex-wrap items-center gap-1.5">
            {row.purgeDueOn !== null && (
                <span className="text-xs text-muted-foreground">
                    {`削除予定日(${formatDate(row.purgeDueOn)})を過ぎたため削除しました。`}
                </span>
            )}
            {row.previewUrl !== null && (
                <RecordAttachmentPreview row={row} url={row.previewUrl} />
            )}
            {row.downloadUrl !== null && (
                <Button variant="outline" size="sm" asChild>
                    <a href={row.downloadUrl} download>
                        <Download aria-hidden="true" />
                        ダウンロード
                    </a>
                </Button>
            )}
            {props.canAttach && <DeleteAttachment {...props} />}
        </span>
    );
}

/** 1 行(名前・大きさ・付けた人・日付と操作) */
function AttachmentItem(props: ItemProps) {
    const { row } = props;
    const attachedOn =
        row.attachedAt === null
            ? null
            : formatDatetime(row.attachedAt).slice(0, 10);
    const meta = [
        fileSizeText(row.size),
        personText(row.attachedBy),
        attachedOn,
    ];

    return (
        <li className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 px-3 py-2.5">
            <FileText
                className="size-[18px] text-muted-foreground"
                aria-hidden="true"
            />
            <span className="grid min-w-0 flex-1 basis-36 gap-0.5">
                <span className="font-medium wrap-anywhere">{row.name}</span>
                <span className="font-num text-xs text-muted-foreground tabular-nums">
                    {meta.filter((part) => part !== null).join('　')}
                </span>
            </span>
            <AttachmentActions {...props} />
        </li>
    );
}

/** 送った誤り(キー attachments・attachments.<添字>) */
function UploadErrors({
    errors,
}: {
    errors: { key: string; message: string }[];
}) {
    return errors.length === 0 ? null : (
        <ul role="alert" className="grid gap-0.5 text-destructive">
            {errors.map((error) => (
                <li key={error.key}>{error.message}</li>
            ))}
        </ul>
    );
}

/**
 * 「ファイルを追加」と送った誤り。
 */
function AddFiles({
    uploadUrl,
    disabled,
}: {
    uploadUrl: string;
    disabled: boolean;
}) {
    const { input, sending, progress, errors, send } =
        useAttachmentUpload(uploadUrl);

    return (
        <>
            <input
                ref={input}
                type="file"
                multiple
                hidden
                aria-label="追加するファイル"
                onChange={(event) => send(event.target.files)}
            />
            <Button
                variant="outline"
                size="sm"
                className="justify-self-start"
                disabled={disabled || sending}
                onClick={() => input.current?.click()}
            >
                <Plus aria-hidden="true" />
                ファイルを追加
            </Button>
            {progress !== null && <SendingProgress progress={progress} />}
            <UploadErrors errors={errors} />
        </>
    );
}

/**
 * サイドパネルの「添付ファイル」の欄(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 9 節): 上に
 * 「ファイルを追加」と案内の 1 行(使用しない業務モジュール・申請中は 1 行)、下に新しい順の一覧と「さらに表示」。
 * 追加と削除は canAttach のときだけ出し、申請中は押せない形にする。URL は呼び手が渡す(部品はルートの名前を知らない)。
 */
export function RecordAttachmentList(props: Props) {
    const { rows, canAttach, held } = props;
    const last = rows.at(-1);
    const line = props.notice ?? (canAttach ? props.guide : null);

    return (
        <div className="grid gap-2.5">
            {canAttach && (
                <AddFiles uploadUrl={props.uploadUrl} disabled={held} />
            )}
            {line !== null && (
                <p className="text-xs text-muted-foreground">{line}</p>
            )}
            {rows.length === 0 ? (
                <p className="text-muted-foreground">
                    添付ファイルはありません
                </p>
            ) : (
                <ul className="divide-y rounded-md border bg-background">
                    {rows.map((row) => (
                        <AttachmentItem
                            key={row.id}
                            row={row}
                            canAttach={canAttach}
                            held={held}
                            deleteAction={props.deleteActionOf(row)}
                        />
                    ))}
                </ul>
            )}
            {props.hasMore && last !== undefined && (
                <RecordPanelMore
                    loading={props.loading}
                    onMore={() => props.onMore(last.id)}
                />
            )}
        </div>
    );
}
