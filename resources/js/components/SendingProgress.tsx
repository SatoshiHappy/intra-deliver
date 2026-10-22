import type { UploadProgress } from '@/lib/recordShow';

/**
 * 送っている間の進み具合(割合が分からない間は動く表示。描き分け = lib/recordShow の uploadProgress)。添付の「ファイルを追加」と
 * 会議のダイアログが使う。
 */
export function SendingProgress({ progress }: { progress: UploadProgress }) {
    return (
        <div className="grid gap-1 text-xs">
            <progress
                className="h-1.5 w-full"
                max={100}
                value={progress.value ?? undefined}
                aria-label="送信の進み具合"
            />
            <span className="text-muted-foreground">{progress.text}</span>
        </div>
    );
}
