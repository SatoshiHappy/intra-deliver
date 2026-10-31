import type { InertiaFormProps } from '@inertiajs/react';
import type { WorkflowApplyAttachment, WorkflowApplyProps } from '@/types';

/**
 * 申請の画面の添付の欄の出し方・外す印・誤りの置き場(docs の 設計/ワークフロー/申請の周辺/画面.md の 2 節・8 節、
 * 設計/ワークフロー/申請の周辺/添付/README.md の 2 節・3.3 節)。
 */

/** 一時保存の添付の行(props の型) */
type Draft = WorkflowApplyAttachment['drafts'][number];

/** 添付が使えず、一時保存の添付の行にほかの利用者が追加した行が無いときの 1 行(文の正本 = 設計/ワークフロー/申請の周辺/画面.md の 8 節) */
const UNUSABLE_LINE =
    'この申請では添付ファイルを使用できません。申請する前に削除してください。';

/** 添付が使えず、ほかの利用者が追加した行があるときの 1 行 */
const UNUSABLE_OTHERS_LINE =
    'この申請では添付ファイルを使用できません。ほかの利用者が追加した添付ファイルがあるため、一時保存を削除して作り直してください。';

/** 面の出し方(full = 中身をすべて、drafts = 一時保存の添付の行と 1 行だけ、none = 面を出さない) */
export type AttachmentSurface =
    | { kind: 'full' }
    | { kind: 'drafts'; line: string }
    | { kind: 'none' };

/**
 * 面の出し方(判定の正本 = 設計/ワークフロー/申請の周辺/画面.md の 2 節)。
 */
export function attachmentSurfaceOf(
    attachment: Pick<WorkflowApplyAttachment, 'enabled' | 'drafts'>,
): AttachmentSurface {
    if (attachment.enabled) {
        return { kind: 'full' };
    }

    if (attachment.drafts.length === 0) {
        return { kind: 'none' };
    }

    const hasOthers = attachment.drafts.some((draft) => !draft.removable);

    return {
        kind: 'drafts',
        line: hasOthers ? UNUSABLE_OTHERS_LINE : UNUSABLE_LINE,
    };
}

/**
 * 見せる・送るファイル(使えるときだけ選んだファイル。使えないなら選んであっても出さず、送らない)。
 */
export function filesToSendOf(
    attachment: Pick<WorkflowApplyAttachment, 'enabled'>,
    files: File[],
): File[] {
    return attachment.enabled ? files : [];
}

/**
 * 外す印の付いた一時保存の添付の id(今ある、外せる行だけ。行の並びの順)。読み直しで props から消えた行の印は、ここで落ちる。
 */
export function removalsOf(marks: number[], drafts: Draft[]): number[] {
    return drafts
        .filter((draft) => draft.removable && marks.includes(draft.id))
        .map((draft) => draft.id);
}

/**
 * 外す印を付ける・元に戻す(外せない行・今ない行は印を付けない)。
 */
export function toggledMarksOf(
    marks: number[],
    drafts: Draft[],
    id: number,
): number[] {
    const current = removalsOf(marks, drafts);

    if (current.includes(id)) {
        return current.filter((mark) => mark !== id);
    }

    return removalsOf([...current, id], drafts);
}

/**
 * 送る remove_attachment_ids。一時保存の画面(一時保存の上書き・一時保存からの申請)だけが送り、ほかの画面は送らない(設計/ワークフロー/申請の周辺/添付/README.md の 2 節)。
 */
export function removeIdsPayloadOf(
    mode: WorkflowApplyProps['mode'],
    removals: number[],
): { remove_attachment_ids?: number[] } {
    return mode === 'draft' ? { remove_attachment_ids: removals } : {};
}

/** 添付の誤りの置き場(rows = 選んだファイルの行の下、surface = 面の下) */
export type AttachmentErrors = {
    rows: Record<number, string>;
    surface: string[];
};

const ROW_KEY = /^attachments\.(\d+)$/;

/** attachments.<添字> の添字(そのキーでなければ null) */
function rowIndexOf(key: string): number | null {
    const index = ROW_KEY.exec(key)?.[1];

    return index === undefined ? null : Number(index);
}

/**
 * 出さない誤りか。判定を 1 か所にして、面の下とフォームの先頭の出し方をそろえる(設計/ワークフロー/申請の周辺/画面.md の 2 節の
 * 「使えなくなったとき」)。
 */
export function isHiddenAttachmentError(
    key: string,
    enabled: boolean,
): boolean {
    return !enabled && ROW_KEY.test(key);
}

/**
 * 添付の誤りの置き場(設計/ワークフロー/申請の周辺/画面.md の 2 節)。行の無い添字は面の下に寄せる(どの誤りも画面のどこかに出すため)。
 */
export function attachmentErrorsOf(
    errors: Record<string, string | undefined>,
    fileCount: number,
    enabled: boolean,
): AttachmentErrors {
    const placed: AttachmentErrors = { rows: {}, surface: [] };

    for (const key of Object.keys(errors).sort()) {
        const message = errors[key];

        if (
            message === undefined ||
            !isAttachmentKey(key) ||
            isHiddenAttachmentError(key, enabled)
        ) {
            continue;
        }

        const index = rowIndexOf(key);

        if (index !== null && index < fileCount) {
            placed.rows[index] = message;
        } else {
            placed.surface.push(message);
        }
    }

    return placed;
}

/**
 * ファイルの並びに当たる誤り(attachments・attachments.<添字>)のキー。誤りは送った時の並びにだけ当たるので、並びを変えたら消す。
 * remove_attachment_ids は並びに当たらない。
 */
export function fileErrorKeysOf(
    errors: Record<string, string | undefined>,
): string[] {
    return Object.keys(errors).filter(isFileErrorKey);
}

/**
 * ファイルの並びに当たる誤りを消す(ほかの誤りは残す。申請の画面と処理のダイアログが使う)。消すキーが無いときに clearErrors を呼ぶと
 * 全部の誤りを消すので呼ばない。キーはフォームの型に無い(サーバーの誤りのキーは送る形の attachments.<添字>)ので、clearErrors の引数の型に指定する。
 */
export function clearFileErrors<T extends object>(
    form: Pick<InertiaFormProps<T>, 'errors' | 'clearErrors'>,
) {
    const keys = fileErrorKeysOf(form.errors);

    if (keys.length > 0) {
        form.clearErrors(
            ...(keys as Parameters<InertiaFormProps<T>['clearErrors']>),
        );
    }
}

/** ファイルの並びに当たる誤りのキーか(attachments・attachments.<添字>) */
function isFileErrorKey(key: string): boolean {
    return key === 'attachments' || ROW_KEY.test(key);
}

/**
 * 添付の誤りのキーか(attachments・attachments.<添字>・remove_attachment_ids)。
 */
export function isAttachmentKey(key: string): boolean {
    return isFileErrorKey(key) || key === 'remove_attachment_ids';
}
