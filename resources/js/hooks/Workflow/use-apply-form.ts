import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useApplyReload } from '@/hooks/Workflow/use-apply-reload';
import { useApplicationTarget } from '@/hooks/Workflow/use-application-target';
import { applyPayloadOf, initialApplyFormOf } from '@/lib/Workflow/applyForm';
import type { ApplyForm, ApplySendKind } from '@/lib/Workflow/applyForm';
import { toastedErrorsOf } from '@/lib/Workflow/applyScreen';
import { openingOf } from '@/lib/Workflow/applicationTarget';
import { toastErrors } from '@/lib/flashToast';
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

    /** 送る内容は applyPayloadOf(申請者・連絡・申請基準日をどの操作で送るかもそこで決まる) */
    const send = (
        action: RouteDefinition<'post' | 'put'>,
        kind: ApplySendKind,
    ) => {
        if (applicationTarget.dialog.isBusy) {
            return;
        }

        form.transform((data) => applyPayloadOf(props, data, kind));
        form.submit(action, {
            onError: (errors) => {
                toastErrors(toastedErrorsOf(props.mode, errors));
                setFocusRequest((count) => count + 1);
            },
        });
    };

    return {
        form,
        errors,
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
