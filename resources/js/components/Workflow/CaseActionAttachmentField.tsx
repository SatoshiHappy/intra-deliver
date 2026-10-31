import { Label } from '@/components/ui/label';
import { AddedFiles } from '@/components/Workflow/ApplyAttachmentRows';
import {
    PickFiles,
    SurfaceErrors,
} from '@/components/Workflow/AttachmentPicker';
import type { CaseActionFormState } from '@/hooks/Workflow/use-case-action-form';
import { attachmentErrorsOf } from '@/lib/Workflow/applyAttachment';

const LABEL_ID = 'case-action-attachment-label';

/**
 * 処理のダイアログの添付ファイルの欄(中身・出す条件の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 3 節)。
 * 欄を出さないときの誤りの置き場は `CaseActionForm`(`noticeErrorOf`)。
 */
export function CaseActionAttachmentField({
    attachment,
    errors,
}: {
    attachment: CaseActionFormState['attachment'];
    errors: Record<string, string | undefined>;
}) {
    const { attachable, limits, files, addFiles, removeFile } = attachment;

    if (!attachable || limits === null) {
        return null;
    }

    const placed = attachmentErrorsOf(errors, files.length, attachable);

    return (
        <div
            role="group"
            aria-labelledby={LABEL_ID}
            className="flex flex-col gap-2"
        >
            <Label id={LABEL_ID}>添付ファイル</Label>
            <p className="text-sm text-muted-foreground">{limits.guide}</p>
            <PickFiles accept={limits.accept} onPick={addFiles} />
            <AddedFiles
                files={files}
                errors={placed.rows}
                onRemove={removeFile}
            />
            <SurfaceErrors messages={placed.surface} />
        </div>
    );
}
