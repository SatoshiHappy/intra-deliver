import { FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { fileSizeText } from '@/lib/recordShow';
import { cn } from '@/lib/utils';
import type { WorkflowApplyAttachment } from '@/types';

/** 行の並び(名前・大きさと、右の操作)の見出しと枠 */
function FileGroup({
    caption,
    children,
}: {
    caption: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <h3 className="text-sm font-medium text-muted-foreground">
                {caption}
            </h3>
            <ul className="divide-y rounded-md border bg-background">
                {children}
            </ul>
        </div>
    );
}

/** 1 行(名前・大きさ。操作と誤りは呼ぶ側が渡す) */
function FileRow({
    name,
    size,
    struck = false,
    error,
    children,
}: {
    name: string;
    size: number;
    struck?: boolean;
    error?: string;
    children?: ReactNode;
}) {
    return (
        <li className="flex flex-wrap items-center gap-x-2.5 gap-y-1 px-3 py-2.5">
            <FileText
                className="size-[18px] text-muted-foreground"
                aria-hidden="true"
            />
            <span
                className={cn(
                    'grid min-w-0 flex-1 basis-36 gap-0.5',
                    struck && 'text-muted-foreground line-through',
                )}
            >
                <span className="font-medium wrap-anywhere">{name}</span>
                <span className="font-num text-xs tabular-nums">
                    {fileSizeText(size)}
                </span>
            </span>
            {children}
            {error !== undefined && (
                <p
                    role="alert"
                    tabIndex={-1}
                    className="basis-full text-sm text-destructive"
                >
                    {error}
                </p>
            )}
        </li>
    );
}

/** 行の操作のボタン(名前は「<ファイル名>を<操作>」で読み上げる) */
function RowButton({
    name,
    label,
    onClick,
}: {
    name: string;
    label: string;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            aria-label={`${name}を${label}`}
            onClick={onClick}
        >
            {label}
        </Button>
    );
}

/**
 * 今回追加するファイル(名前・大きさ・「削除」= 送る前の選び直し)。誤り attachments.<添字> はそのファイルの行の下に出す。
 */
export function AddedFiles({
    files,
    errors,
    onRemove,
}: {
    files: File[];
    errors: Record<number, string>;
    onRemove: (file: File) => void;
}) {
    if (files.length === 0) {
        return null;
    }

    return (
        <FileGroup caption="今回追加するファイル">
            {files.map((file, index) => (
                <FileRow
                    key={`${index}-${file.name}`}
                    name={file.name}
                    size={file.size}
                    error={errors[index]}
                >
                    <RowButton
                        name={file.name}
                        label="削除"
                        onClick={() => onRemove(file)}
                    />
                </FileRow>
            ))}
        </FileGroup>
    );
}

/**
 * 一時保存の添付(名前・大きさ。外せる行だけ「削除」= 外す印を付けて取り消し線を引き、「元に戻す」で印を外す)。
 */
export function DraftFiles({
    drafts,
    removals,
    onToggle,
}: {
    drafts: WorkflowApplyAttachment['drafts'];
    removals: number[];
    onToggle: (id: number) => void;
}) {
    if (drafts.length === 0) {
        return null;
    }

    return (
        <FileGroup caption="一時保存の添付">
            {drafts.map((draft) => {
                const removed = removals.includes(draft.id);

                return (
                    <FileRow
                        key={draft.id}
                        name={draft.name}
                        size={draft.size}
                        struck={removed}
                    >
                        {draft.removable && (
                            <RowButton
                                name={draft.name}
                                label={removed ? '元に戻す' : '削除'}
                                onClick={() => onToggle(draft.id)}
                            />
                        )}
                    </FileRow>
                );
            })}
        </FileGroup>
    );
}

/**
 * 付いている添付(再申請・既存のデータからの申請。名前・大きさだけで、「削除」は出さない)。読んだ件数より全部の件数が多いときは残りの件数を添える。
 */
export function AttachedFiles({
    attached,
    count,
}: {
    attached: WorkflowApplyAttachment['attached'];
    count: number;
}) {
    if (attached.length === 0) {
        return null;
    }

    const rest = count - attached.length;

    return (
        <FileGroup caption="付いている添付">
            {attached.map((file, index) => (
                <FileRow
                    key={`${index}-${file.name}`}
                    name={file.name}
                    size={file.size}
                />
            ))}
            {rest > 0 && (
                <li className="px-3 py-2.5 text-sm text-muted-foreground">
                    ほかに {rest} 件
                </li>
            )}
        </FileGroup>
    );
}
