import { FormSurface } from '@/components/FormLayout';
import {
    PickFiles,
    SurfaceErrors,
} from '@/components/Workflow/AttachmentPicker';
import {
    AddedFiles,
    AttachedFiles,
    DraftFiles,
} from '@/components/Workflow/ApplyAttachmentRows';
import type { useApplyAttachment } from '@/hooks/Workflow/use-apply-attachment';
import type { useApplyForm } from '@/hooks/Workflow/use-apply-form';
import {
    attachmentErrorsOf,
    attachmentSurfaceOf,
} from '@/lib/Workflow/applyAttachment';
import type { WorkflowApplyAttachment, WorkflowApplyProps } from '@/types';

type ApplyAttachmentState = ReturnType<typeof useApplyAttachment>;

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
