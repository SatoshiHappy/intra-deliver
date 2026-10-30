import { useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useState } from 'react';
import { useApplyAttachment } from '@/hooks/Workflow/use-apply-attachment';
import { sendApply } from '@/hooks/Workflow/use-apply-send';
import { useApplyReload } from '@/hooks/Workflow/use-apply-reload';
import { useApplicationTarget } from '@/hooks/Workflow/use-application-target';
import { fileErrorKeysOf } from '@/lib/Workflow/applyAttachment';
import { initialApplyFormOf } from '@/lib/Workflow/applyForm';
import type { ApplyForm, ApplySendKind } from '@/lib/Workflow/applyForm';
import { openingOf } from '@/lib/Workflow/applicationTarget';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/** clearErrors の引数(ApplyForm のキー) */
type ClearErrorsKeys = Parameters<InertiaFormProps<ApplyForm>['clearErrors']>;

/**
 * ファイルの並びに当たる誤りを消す(ほかの誤りは残す)。消すキーが無いときに clearErrors を呼ぶと全部の誤りを消すので呼ばない。
 * キーは ApplyForm の型に無い(サーバーの誤りのキーは送る形の attachments.<添字>)ので、clearErrors の引数の型に指定する。
 */
export function clearFileErrors(
    form: Pick<InertiaFormProps<ApplyForm>, 'errors' | 'clearErrors'>,
) {
    const keys = fileErrorKeysOf(form.errors);

    if (keys.length > 0) {
        form.clearErrors(...(keys as ClearErrorsKeys));
    }
}

/**
 * 申請・一時保存・再申請のフォームの状態と送信(docs の 設計/ワークフロー/画面/申請と案件.md 3 節)。
 * focusRequest は送信の失敗ごとに増え、呼ぶ側が誤りのある欄へフォーカスを移す合図に使う。
 */
export function useApplyForm(props: WorkflowApplyProps) {
    const [opened] = useState(() => {
        const initial = initialApplyFormOf(props);
        const opening = openingOf(props, initial.record);

        return { opening, data: { ...initial, record: opening.record } };
    });
    const form = useForm<ApplyForm>(opened.data);
    const errors = form.errors as Record<string, string | undefined>;
    const [focusRequest, setFocusRequest] = useState(0);
    const applicationTarget = useApplicationTarget(form, props, opened.opening);
    const reload = useApplyReload(form, props, applicationTarget.reselected);

    const attachment = useApplyAttachment(props.attachment, () =>
        clearFileErrors(form),
    );

    const send = (
        action: RouteDefinition<'post' | 'put'>,
        kind: ApplySendKind,
    ) =>
        sendApply(
            {
                form,
                props,
                attachment,
                isBusy: applicationTarget.dialog.isBusy,
                onFailed: () => setFocusRequest((count) => count + 1),
            },
            action,
            kind,
        );

    return {
        form,
        errors,
        attachment,
        focusRequest,
        send,
        changeBaseDate: reload.changeBaseDate,
        changeRecord: applicationTarget.changeRecord,
        changeApplicant: (applicant: number | null) =>
            applicationTarget.changeApplicant(
                applicant,
                reload.changeApplicant,
            ),
        applicationTarget: applicationTarget.dialog,
    };
}
