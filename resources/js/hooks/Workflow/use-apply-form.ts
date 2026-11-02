import { router, useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { useApplyAttachment } from '@/hooks/Workflow/use-apply-attachment';
import { sendApply } from '@/hooks/Workflow/use-apply-send';
import {
    useApplyOpened,
    useStripCopyQueries,
} from '@/hooks/Workflow/use-apply-opened';
import { useApplyReload } from '@/hooks/Workflow/use-apply-reload';
import { useApplicationTarget } from '@/hooks/Workflow/use-application-target';
import { clearFileErrors } from '@/lib/Workflow/applyAttachment';
import { NEXT_FAILED_MESSAGE } from '@/lib/Workflow/applyReload';
import type { ApplyReloadStatus } from '@/lib/Workflow/applyReload';
import type { ApplyForm, ApplySendKind } from '@/lib/Workflow/applyForm';
import { openedFormOf, rememberKeyOf } from '@/lib/Workflow/applyOpened';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/**
 * 開いた時のフォーム(戻る・進むで来た時は覚えた入力。docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節)。戻る・進むで来た時は、
 * useForm が覚えた値で始まるので、申請対象を最後に決まったものに戻す値を開いた後に 1 度だけ入れる。
 */
function useOpenedForm(props: WorkflowApplyProps) {
    const [rememberKey] = useState(() => rememberKeyOf(props));
    const [opened] = useState(() =>
        openedFormOf(props, rememberKey, (key) => router.restore(key)),
    );
    const form = useForm<ApplyForm>(rememberKey, opened.data);

    useEffect(() => {
        if (opened.restored) {
            form.setData('record', opened.data.record);
        }
    }, []);

    return { rememberKey, opened, form };
}

/**
 * 申請者・申請基準日の読み直しと、その間と失敗の状態(失敗はトースト = 画面.md の 8 節)。
 */
function useReloadWithStatus(
    form: InertiaFormProps<ApplyForm>,
    props: WorkflowApplyProps,
    onReselected: () => void,
    restored: boolean,
) {
    const [status, setStatus] = useState<ApplyReloadStatus>({
        busy: false,
        stale: false,
    });
    const reload = useApplyReload(
        form,
        props,
        {
            onReselected,
            onStatus: setStatus,
            onFailed: () => toast.error(NEXT_FAILED_MESSAGE),
        },
        restored,
    );

    return { ...reload, status };
}

/**
 * 送信(申請対象の写しか読み直しの間は送らない)と、送信の失敗ごとに増える focusRequest。
 */
function useSend(
    form: InertiaFormProps<ApplyForm>,
    props: WorkflowApplyProps,
    attachment: ReturnType<typeof useApplyAttachment>,
    isBusy: boolean,
) {
    const [focusRequest, setFocusRequest] = useState(0);
    const onFailed = () => setFocusRequest((count) => count + 1);
    const send = (
        action: RouteDefinition<'post' | 'put'>,
        kind: ApplySendKind,
    ) => sendApply({ form, props, attachment, isBusy, onFailed }, action, kind);

    return { send, focusRequest };
}

/**
 * 申請・一時保存・再申請のフォームの状態と送信(docs の 設計/ワークフロー/画面/申請と案件.md 3 節)。
 * focusRequest は送信の失敗ごとに増え、呼ぶ側が誤りのある欄へフォーカスを移す合図に使う。
 */
export function useApplyForm(rawProps: WorkflowApplyProps) {
    const { props, copyMarks } = useApplyOpened(rawProps);
    const { rememberKey, opened, form } = useOpenedForm(props);
    const errors = form.errors as Record<string, string | undefined>;
    const applicationTarget = useApplicationTarget(
        form,
        props,
        opened.opening,
        rememberKey,
    );
    const reload = useReloadWithStatus(
        form,
        props,
        applicationTarget.reselected,
        opened.restored,
    );
    const isBusy = applicationTarget.dialog.isBusy || reload.status.busy;
    const attachment = useApplyAttachment(props.attachment, () =>
        clearFileErrors(form),
    );

    const { send, focusRequest } = useSend(form, props, attachment, isBusy);
    // 覚える effect(開いた時の props・入力・申請対象の写し)のすべての後に置く。部品の子は覚えない
    useStripCopyQueries();

    return {
        /** 開いた時の props を保った props */
        props,
        /** 元の申請の値で空にした欄の印(案件からの複製で開いた画面だけ) */
        copyMarks,
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
        /** 申請対象の写しか申請者・申請基準日の読み直しの間(申請・一時保存を押せない) */
        isBusy,
        /** 最後の申請者・申請基準日の読み直しが失敗した(次のステップの表示の上の添え書き) */
        isNextStale: reload.status.stale,
    };
}
