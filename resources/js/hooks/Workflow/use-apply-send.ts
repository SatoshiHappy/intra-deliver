import type { InertiaFormProps } from '@inertiajs/react';
import { toast } from 'sonner';
import type { useApplyAttachment } from '@/hooks/Workflow/use-apply-attachment';
import { removeIdsPayloadOf } from '@/lib/Workflow/applyAttachment';
import { applyPayloadOf } from '@/lib/Workflow/applyForm';
import type { ApplyForm, ApplySendKind } from '@/lib/Workflow/applyForm';
import { toastedErrorsOf } from '@/lib/Workflow/applyScreen';
import {
    exceedsLimits,
    onPayloadTooLarge,
    sendShapeOf,
} from '@/lib/Workflow/attachmentSend';
import { toastErrors } from '@/lib/flashToast';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/** 送信が頼る画面の状態(申請対象の値を取っている間は送らない。誤りがあれば onFailed で知らせる) */
type SendContext = {
    form: InertiaFormProps<ApplyForm>;
    props: WorkflowApplyProps;
    attachment: ReturnType<typeof useApplyAttachment>;
    isBusy: boolean;
    onFailed: () => void;
};

/**
 * 申請・一時保存の送信(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節・4 節)。送る内容は applyPayloadOf(申請者・連絡・
 * 申請基準日をどの操作で送るかもそこで決まる)に外す印を足し、添付があれば sendShapeOf が multipart にする。選んだファイルが上限を超えるなら
 * 送らずにトースト。サーバーが 413 を返したときも同じトーストを出す(入力と選んだファイルは残る)。
 */
export function sendApply(
    context: SendContext,
    action: RouteDefinition<'post' | 'put'>,
    kind: ApplySendKind,
) {
    const { form, props, attachment } = context;
    const { limits } = props.attachment;
    const { files } = attachment;

    if (context.isBusy) {
        return;
    }

    if (exceedsLimits(files, limits)) {
        toast.error(limits.tooLarge);

        return;
    }

    form.transform((data) =>
        sendShapeOf(
            {
                ...applyPayloadOf(props, data, kind),
                ...removeIdsPayloadOf(props.mode, attachment.removals),
            },
            files,
        ),
    );
    form.submit(action, {
        onSuccess: attachment.reset,
        onHttpException: onPayloadTooLarge(
            (message) => toast.error(message),
            limits.tooLarge,
        ),
        onError: (errors) => {
            toastErrors(toastedErrorsOf(props.mode, errors));
            context.onFailed();
        },
    });
}
