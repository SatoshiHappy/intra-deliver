import { router, useHttp } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import { applicationTargetSnapshotUrlOf } from '@/lib/Workflow/applyRoutes';
import type { ApplicationTargetOpening } from '@/lib/Workflow/applicationTarget';
import type { RememberedTarget } from '@/lib/Workflow/applyOpened';
import {
    failedMessageOf,
    fetchOf,
} from '@/lib/Workflow/applicationTargetFetch';
import {
    applicantChanged,
    applicantReselected,
} from '@/lib/Workflow/applicationTargetApplicant';
import {
    applicationTargetFlowOf,
    cancelApplicationTarget,
    recordChanged,
    reflectApplicationTarget,
} from '@/lib/Workflow/applicationTargetFlow';
import type {
    ApplicationTargetFlow,
    ApplicationTargetPorts,
    ApplicationTargetFetch,
    ApplicationTargetView,
} from '@/lib/Workflow/applicationTargetFlow';
import type { RecordFormData } from '@/lib/recordForm';
import type { ApplicationTargetSnapshot } from '@/types/workflowApplyTarget';
import type { WorkflowApplyProps } from '@/types';

/** 写しが読み書きするフォームの口 */
type ApplicationTargetForm = Pick<
    InertiaFormProps<ApplyForm>,
    'data' | 'setData'
>;

/** 写しの値の取り方(Inertia の useHttp) */
function useApplicationTargetFetch(): ApplicationTargetFetch {
    const http = useHttp<Record<string, never>, ApplicationTargetSnapshot>({});

    return fetchOf((url) => http.get(url));
}

/** 外の口(フォームと props から作る) */
function portsOf(
    form: ApplicationTargetForm,
    props: WorkflowApplyProps,
    host: Pick<ApplicationTargetPorts, 'fetch' | 'publish'>,
): ApplicationTargetPorts {
    const scope = {
        mode: props.mode,
        flowCode: props.flow.code,
        caseId: props.case?.id ?? null,
        recordId: props.recordId,
    };

    return {
        fetch: host.fetch,
        publish: host.publish,
        module: props.module,
        applicant: form.data.applicant,
        // Inertia の setData は、関数を渡すと最新の値で同期して呼ぶ(前の値はその引数で読む)
        updateRecord: (update) => {
            const seen = { before: form.data.record, after: form.data.record };

            form.setData((data) => {
                seen.before = data.record;
                seen.after = update(data.record);

                return { ...data, record: seen.after };
            });

            return seen;
        },
        urlOf: (id, applicant) =>
            applicationTargetSnapshotUrlOf(id, scope, applicant),
        notifyFailed: (failure) => toast.error(failedMessageOf(failure)),
    };
}

/**
 * 戻る・進むで来た時に、最後に決まった申請対象と 1 行を戻すため、履歴の項目に覚える(画面.md の 5 節)。
 */
function rememberTarget(
    applicationTarget: ApplicationTargetFlow,
    cleared: string[],
    rememberKey: string,
) {
    const remembered: RememberedTarget = {
        settled: applicationTarget.state.settled,
        cleared,
    };
    router.remember(remembered, `${rememberKey}:target`);
}

/**
 * 申請の画面の申請対象の値の写し(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」)。申請対象の欄が変わったら
 * changeRecord が写しの値を取り、取っている間は dialog.isBusy、写す前に確かめるなら dialog.confirm を返す。
 */
export function useApplicationTarget(
    form: ApplicationTargetForm,
    props: WorkflowApplyProps,
    opening: ApplicationTargetOpening,
    rememberKey: string,
) {
    const [applicationTarget] = useState(() =>
        applicationTargetFlowOf(props.targetField, opening),
    );
    const [view, setView] = useState<ApplicationTargetView>({
        busy: false,
        confirm: opening.confirm,
        cleared: opening.cleared,
    });
    const remember = (cleared: string[]) =>
        rememberTarget(applicationTarget, cleared, rememberKey);
    const ports = portsOf(form, props, {
        fetch: useApplicationTargetFetch(),
        publish: (next) => {
            setView(next);
            remember(next.cleared);
        },
    });

    useEffect(() => remember(opening.cleared), []);

    return {
        /** 写す前の確かめのダイアログと、取っている間の印と、空にした項目の 1 行の名前 */
        dialog: {
            isBusy: view.busy,
            confirm: view.confirm,
            cleared: view.cleared,
            reflect: () => reflectApplicationTarget(applicationTarget, ports),
            cancel: () => cancelApplicationTarget(applicationTarget, ports),
        },
        // 何も返さない(応答を当て終わるのを呼び手は待たない)。HTTP の失敗でない例外は未処理の拒否として上がる(握りつぶさない)
        changeRecord: (update: (record: RecordFormData) => RecordFormData) => {
            void recordChanged(applicationTarget, ports, update);
        },
        changeApplicant: (
            applicant: number | null,
            change: (applicant: number | null) => void,
        ) => applicantChanged(applicationTarget, ports, applicant, change),
        reselected: () => applicantReselected(applicationTarget, ports),
    };
}
