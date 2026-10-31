import type { InertiaFormProps } from '@inertiajs/react';
import { toast } from 'sonner';
import type { PageAttach } from '@/lib/Workflow/caseActionAttach';
import { payloadOf } from '@/lib/Workflow/caseActionForm';
import type { ActionForm } from '@/lib/Workflow/caseActionForm';
import type { CaseActionTarget } from '@/lib/Workflow/caseActions';
import {
    exceedsLimits,
    onPayloadTooLarge,
    sendShapeOf,
} from '@/lib/Workflow/attachmentSend';
import type { WorkflowDesignation } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/** 送信が頼る状態(選んだファイルは可否が偽なら空) */
type SendContext = {
    form: InertiaFormProps<ActionForm>;
    target: CaseActionTarget;
    assigneeFields: WorkflowDesignation[];
    files: File[];
    limits: PageAttach['limits'];
    onSuccess: () => void;
};

/**
 * 処理の送信(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節・4 節)。選んだファイルが上限を超えるなら送らずにトースト、
 * 送るときはファイルがあれば sendShapeOf が multipart にする。サーバーが 413 を返したときも同じトーストを出す(入力と選んだファイルは残る)。
 */
export function sendCaseAction(
    { form, target, assigneeFields, files, limits, onSuccess }: SendContext,
    action: RouteDefinition<'post'>,
) {
    if (limits !== null && exceedsLimits(files, limits)) {
        toast.error(limits.tooLarge);

        return;
    }

    form.transform((data) =>
        sendShapeOf(payloadOf(data, target, assigneeFields), files),
    );
    form.submit(action, {
        preserveScroll: true,
        onSuccess,
        ...(limits === null
            ? {}
            : {
                  onHttpException: onPayloadTooLarge(
                      (message) => toast.error(message),
                      limits.tooLarge,
                  ),
              }),
    });
}
