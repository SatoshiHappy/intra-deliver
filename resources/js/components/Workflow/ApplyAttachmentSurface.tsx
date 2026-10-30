import { useRef } from 'react';
import type { ChangeEvent } from 'react';
import { FormSurface } from '@/components/FormLayout';
import {
    AddedFiles,
    AttachedFiles,
    DraftFiles,
} from '@/components/Workflow/ApplyAttachmentRows';
import { Button } from '@/components/ui/button';
import type { useApplyAttachment } from '@/hooks/Workflow/use-apply-attachment';
import type { useApplyForm } from '@/hooks/Workflow/use-apply-form';
import {
    attachmentErrorsOf,
    attachmentSurfaceOf,
} from '@/lib/Workflow/applyAttachment';
import type { WorkflowApplyAttachment, WorkflowApplyProps } from '@/types';

type ApplyAttachmentState = ReturnType<typeof useApplyAttachment>;

/** 「ファイルを選択」(複数。accept は props の形式の対応表の拡張子。選び終えたら同じファイルをまた選べるよう値を空にする) */
function PickFiles({
    accept,
    onPick,
}: {
    accept: string;
    onPick: (files: File[]) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const change = (event: ChangeEvent<HTMLInputElement>) => {
        onPick(Array.from(event.target.files ?? []));
        event.target.value = '';
    };

    return (
        <>
            <input
                ref={input}
                type="file"
                multiple
                hidden
                accept={accept}
                aria-label="添付するファイル"
                onChange={change}
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start"
                onClick={() => input.current?.click()}
            >
                ファイルを選択
            </Button>
        </>
    );
}

/** 面の下に出す誤り(attachments・remove_attachment_ids) */
function SurfaceErrors({ messages }: { messages: string[] }) {
    return messages.map((message) => (
        <p
            key={message}
            role="alert"
            tabIndex={-1}
            className="text-sm text-destructive"
        >
            {message}
        </p>
    ));
}

/** 使えるときの中身(並びの正本 = 設計/ワークフロー/申請の周辺/画面.md の 2 節) */
function FullContent({
    attachment,
    state,
    rowErrors,
}: {
    attachment: WorkflowApplyAttachment;
    state: ApplyAttachmentState;
    rowErrors: Record<number, string>;
}) {
    return (
        <>
            <p className="text-sm text-muted-foreground">
                {attachment.limits.guide}
            </p>
            <PickFiles
                accept={attachment.limits.accept}
                onPick={state.addFiles}
            />
            <AddedFiles
                files={state.files}
                errors={rowErrors}
                onRemove={state.removeFile}
            />
            <DraftFiles
                drafts={attachment.drafts}
                removals={state.removals}
                onToggle={state.toggleRemoval}
            />
            <AttachedFiles
                attached={attachment.attached}
                count={attachment.attachedCount}
            />
        </>
    );
}

/** 使えないが一時保存の添付が残るときの中身(行と 1 行だけ。「削除」は外せる行だけ) */
function DraftsContent({
    attachment,
    state,
    line,
}: {
    attachment: WorkflowApplyAttachment;
    state: ApplyAttachmentState;
    line: string;
}) {
    return (
        <>
            <DraftFiles
                drafts={attachment.drafts}
                removals={state.removals}
                onToggle={state.toggleRemoval}
            />
            <p className="text-sm text-muted-foreground">{line}</p>
        </>
    );
}

/**
 * 申請の画面の面「添付ファイル」(docs の 設計/ワークフロー/申請の周辺/画面.md の 2 節)。出し方は attachmentSurfaceOf が決める。
 */
export function ApplyAttachmentSurface({
    props,
    apply,
}: {
    props: WorkflowApplyProps;
    apply: Pick<ReturnType<typeof useApplyForm>, 'attachment' | 'errors'>;
}) {
    const { attachment } = props;
    const state = apply.attachment;
    const surface = attachmentSurfaceOf(attachment);

    if (surface.kind === 'none') {
        return null;
    }

    const placed = attachmentErrorsOf(
        apply.errors,
        state.files.length,
        attachment.enabled,
    );

    return (
        <FormSurface aria-labelledby="apply-attachment-heading">
            <h2
                id="apply-attachment-heading"
                className="text-[15px] font-semibold"
            >
                添付ファイル
            </h2>
            {surface.kind === 'full' ? (
                <FullContent
                    attachment={attachment}
                    state={state}
                    rowErrors={placed.rows}
                />
            ) : (
                <DraftsContent
                    attachment={attachment}
                    state={state}
                    line={surface.line}
                />
            )}
            <SurfaceErrors messages={placed.surface} />
        </FormSurface>
    );
}
