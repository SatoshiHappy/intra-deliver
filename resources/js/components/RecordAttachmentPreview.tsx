import { Eye } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { previewKind } from '@/lib/recordShow';
import type { RecordAttachmentRow } from '@/types';

type Props = { row: RecordAttachmentRow; url: string };

/**
 * 「プレビュー」を押すと開く Dialog(題 = 名前)。中身は呼び手が渡す(画像・音声・動画)。
 */
function PreviewDialog({
    row,
    children,
}: {
    row: RecordAttachmentRow;
    children: React.ReactNode;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <Eye aria-hidden="true" />
                    プレビュー
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="wrap-anywhere">
                        {row.name}
                    </DialogTitle>
                </DialogHeader>
                {children}
            </DialogContent>
        </Dialog>
    );
}

/**
 * 音声・動画の標準の再生の部品(controls。自動では再生しない。Dialog を閉じると中身ごと消えて止まる)。
 */
function Player({ kind, url }: { kind: 'audio' | 'video'; url: string }) {
    return kind === 'audio' ? (
        <audio controls preload="metadata" src={url} className="w-full" />
    ) : (
        <video
            controls
            preload="metadata"
            src={url}
            className="max-h-[70svh] w-full"
        />
    );
}

/**
 * プレビュー(画像(GIF を含む)・音声・動画 = Dialog の中、PDF・テキスト・Markdown・CSV = 新しいタブ。添付ファイル/README.md の 4 節)。
 */
export function RecordAttachmentPreview({ row, url }: Props) {
    const kind = previewKind(row.mime);

    if (kind === 'image') {
        return (
            <PreviewDialog row={row}>
                <img
                    src={url}
                    alt={row.name}
                    className="max-h-[70svh] w-full object-contain"
                />
            </PreviewDialog>
        );
    }

    if (kind === 'audio' || kind === 'video') {
        return (
            <PreviewDialog row={row}>
                <Player kind={kind} url={url} />
            </PreviewDialog>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <a href={url} target="_blank" rel="noopener noreferrer">
                <Eye aria-hidden="true" />
                プレビュー
            </a>
        </Button>
    );
}
