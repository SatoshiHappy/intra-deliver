import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useApplyAttachment } from '@/hooks/Workflow/use-apply-attachment';
import { sendApply } from '@/hooks/Workflow/use-apply-send';
import { useApplyReload } from '@/hooks/Workflow/use-apply-reload';
import { useApplicationTarget } from '@/hooks/Workflow/use-application-target';
import { clearFileErrors } from '@/lib/Workflow/applyAttachment';
import { initialApplyFormOf } from '@/lib/Workflow/applyForm';
import type { ApplyForm, ApplySendKind } from '@/lib/Workflow/applyForm';
import { openingOf } from '@/lib/Workflow/applicationTarget';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

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
