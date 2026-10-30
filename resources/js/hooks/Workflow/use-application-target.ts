import { useHttp } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import { applicationTargetSnapshotUrlOf } from '@/lib/Workflow/applyRoutes';
import type { ApplicationTargetOpening } from '@/lib/Workflow/applicationTarget';
import { fetchOf } from '@/lib/Workflow/applicationTargetFetch';
import {
    applicantChanged,
    applicantReselected,
    applicationTargetFlowOf,
    cancelApplicationTarget,
    recordChanged,
    reflectApplicationTarget,
} from '@/lib/Workflow/applicationTargetFlow';
import type {
    ApplicationTargetPorts,
    ApplicationTargetFetch,
    ApplicationTargetView,
} from '@/lib/Workflow/applicationTargetFlow';
import type { RecordFormData } from '@/lib/recordForm';
import type { ApplicationTargetSnapshot } from '@/types/workflowApplyTarget';
import type { WorkflowApplyProps } from '@/types';

/** 写しの値を取れなかったときのトースト(403・404 に限らず、どの失敗でも出す。参照の候補の取得の失敗と同じ扱い。文の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 8 節) */
const APPLICATION_TARGET_FAILED_MESSAGE =
    '申請対象の値を取得できませんでした。';

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
        notifyFailed: () => toast.error(APPLICATION_TARGET_FAILED_MESSAGE),
    };
}

/**
 * 申請の画面の申請対象の値の写し(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」)。申請対象の欄が変わったら
 * changeRecord が写しの値を取り、取っている間は dialog.isBusy、写す前に確かめるなら dialog.confirm を返す。
 */
export function useApplicationTarget(
    form: ApplicationTargetForm,
    props: WorkflowApplyProps,
    opening: ApplicationTargetOpening,
) {
    const [applicationTarget] = useState(() =>
        applicationTargetFlowOf(props.targetField, opening),
    );
    const [view, setView] = useState<ApplicationTargetView>({
        busy: false,
        confirm: opening.confirm,
    });
    const ports = portsOf(form, props, {
        fetch: useApplicationTargetFetch(),
        publish: setView,
    });

    return {
        /** 写す前の確かめのダイアログと、取っている間の印 */
        dialog: {
            isBusy: view.busy,
            confirm: view.confirm,
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
