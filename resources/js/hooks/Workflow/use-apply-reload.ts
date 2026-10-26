import { router } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useState } from 'react';
import { clearRecordReferences } from '@/lib/Workflow/applyApplicant';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import {
    applyReloadOf,
    loadedApplicantOf,
    staleApplicantsReloadOf,
} from '@/lib/Workflow/applyReload';
import type {
    ApplyReloadChange,
    ApplyReloadRequest,
    ApplyReloadScope,
} from '@/lib/Workflow/applyReload';
import type { WorkflowApplyProps } from '@/types';

/** 部分の読み直しの送り先(router.reload の形。確かめるときは偽物を渡す) */
export type ApplyReloadSend = (options: {
    data: ApplyReloadRequest['data'];
    only: string[];
    replace: boolean;
    onCancelToken: (token: { cancel: () => void }) => void;
    onSuccess: (page: { props: unknown }) => void;
    onFinish: () => void;
}) => void;

/** 読み直しが触るフォームの口 */
export type ApplyReloadForm = Pick<
    InertiaFormProps<ApplyForm>,
    'data' | 'setData'
>;

type Pending = { cancel: (() => void) | null; only: string[] };

type LoadedProps = Pick<
    WorkflowApplyProps,
    'applicants' | 'applicant' | 'baseDate'
>;

/** 読み直しの状態(送り先・開いたときの値・待っている要求) */
type Reloader = {
    send: ApplyReloadSend;
    scope: ApplyReloadScope;
    pending: Pending | null;
};

function cancelPending(reloader: Reloader) {
    const cancel = reloader.pending?.cancel;
    reloader.pending = null;
    cancel?.();
}

/** 終わった要求を待ちから外す(後から送った要求の分は消さない) */
function settle(reloader: Reloader, request: Pending) {
    if (reloader.pending === request) {
        reloader.pending = null;
    }
}

function reload(
    reloader: Reloader,
    request: ApplyReloadRequest,
    form: ApplyReloadForm,
) {
    cancelPending(reloader);
    const mine: Pending = { cancel: null, only: request.only };
    reloader.pending = mine;
    reloader.send({
        ...request,
        replace: true,
        onCancelToken: (token) => {
            mine.cancel = token.cancel;
        },
        onSuccess: (page) => {
            // 応答を受け取った後は取消が効かないので、後から送った要求か取消がある古い応答はフォームへ写さない
            if (reloader.pending !== mine) {
                return;
            }

            settle(reloader, mine);
            loaded(reloader, request.only, page.props as LoadedProps, form);
        },
        onFinish: () => settle(reloader, mine),
    });
}

/** 申請者に依る props を読み直したら、サーバーが決めた申請者と申請基準日をフォームへ写す(選択肢に無い申請者なら選択肢も読み直す) */
function loaded(
    reloader: Reloader,
    only: string[],
    props: LoadedProps,
    form: ApplyReloadForm,
) {
    const values = loadedApplicantOf(only, props);

    if (values === null) {
        return;
    }

    form.setData((data) => ({ ...data, ...values }));
    const stale = staleApplicantsReloadOf(props, reloader.scope);

    if (stale !== null) {
        reload(reloader, stale, form);
    }
}

function change(
    reloader: Reloader,
    value: ApplyReloadChange,
    form: ApplyReloadForm,
) {
    const current = {
        applicant: form.data.applicant,
        baseDate: form.data.base_date,
    };
    const pendingOnly = reloader.pending?.only ?? null;
    const request = applyReloadOf(value, current, pendingOnly, reloader.scope);

    if (request === null) {
        cancelPending(reloader);
    } else {
        reload(reloader, request, form);
    }
}

/**
 * 申請の画面の部分の読み直し(docs の 設計/ワークフロー/ルートの深さ/画面.md 5 節、案件の運用/画面/案件の画面.md の 6.1 節)。
 * 送る直前に待っている要求を取り消し、最後に送った要求の応答だけを残す。待っている要求はその要求が終わったら消す(終わった要求の only を
 * 引き継がないため)。戻るで食い違わないよう履歴は積まない(replace)。フックの外に置き、送り先を差し替えて確かめられるようにした。
 */
export function applyReloaderOf(
    send: ApplyReloadSend,
    scope: ApplyReloadScope,
) {
    const reloader: Reloader = { send, scope, pending: null };

    /** 申請基準日を変えたら next を引き直す(空にしたら、待っている申請者の読み直しがあるときだけ送り直す) */
    const changeBaseDate = (form: ApplyReloadForm, value: string) => {
        form.setData('base_date', value);
        change(reloader, { field: 'base_date', value }, form);
    };

    /** 申請者を変えたら、選んである他モジュール参照の値を消し、申請者に依る props を引き直す(候補の引き先も新しい申請者になる) */
    const changeApplicant = (
        form: ApplyReloadForm,
        module: WorkflowApplyProps['module'],
        applicant: number | null,
    ) => {
        if (applicant === form.data.applicant) {
            return;
        }

        form.setData((data) => ({
            ...data,
            applicant,
            record: clearRecordReferences(module, data.record),
        }));
        change(reloader, { field: 'applicant', value: applicant }, form);
    };

    return { changeBaseDate, changeApplicant };
}

/**
 * 申請の画面の部分の読み直し(申請基準日・申請者を変えたとき)。送り先と開いたときの値は画面を開いたときに 1 回だけ決める。
 */
export function useApplyReload(
    form: InertiaFormProps<ApplyForm>,
    props: WorkflowApplyProps,
) {
    const [reloader] = useState(() =>
        applyReloaderOf((options) => router.reload(options), {
            openedApplicant: props.applicant,
            fromTarget: props.targetEntry !== null,
        }),
    );

    return {
        changeBaseDate: (value: string) => reloader.changeBaseDate(form, value),
        changeApplicant: (applicant: number | null) =>
            reloader.changeApplicant(form, props.module, applicant),
    };
}
