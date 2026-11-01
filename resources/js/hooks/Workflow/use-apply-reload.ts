import { router } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { clearRecordReferences } from '@/lib/Workflow/applyApplicant';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import {
    applyReloadOf,
    loadedApplicantOf,
    propsUnionOf,
    staleApplicantsReloadOf,
    staleReloadOf,
} from '@/lib/Workflow/applyReload';
import type {
    ApplyReloadEvents,
    ApplyReloadChange,
    ApplyReloadRequest,
} from '@/lib/Workflow/applyReload';
import type { WorkflowApplyProps } from '@/types';

/** 部分の読み直しの送り先(router.reload の形。確かめるときは偽物を渡す) */
export type ApplyReloadSend = (options: {
    data: ApplyReloadRequest['data'];
    only: string[];
    replace: boolean;
    onCancelToken: (token: { cancel: () => void }) => void;
    onSuccess: (page: { props: unknown }) => void;
    /** 応答の誤り(403・404 を含む)と応答の無い失敗。false を返して Inertia の既定の出し方を止める */
    onHttpException: () => false;
    onNetworkError: () => false;
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

/** 読み直しの状態(送り先・待っている要求・失敗で古いままの props = 失敗した要求の only の和。空なら古くない) */
type Reloader = {
    send: ApplyReloadSend;
    pending: Pending | null;
    stale: string[];
    events: ApplyReloadEvents;
};

function publish(reloader: Reloader) {
    reloader.events.onStatus({
        busy: reloader.pending !== null,
        stale: reloader.stale.length > 0,
    });
}

function cancelPending(reloader: Reloader) {
    const cancel = reloader.pending?.cancel;
    reloader.pending = null;
    cancel?.();
}

/** 今の要求の失敗(トーストと添え書き。欄は変えた値のまま、押せる状態に戻るのは onFinish)。取り消した要求は数えない */
function failed(reloader: Reloader, request: Pending): false {
    if (reloader.pending === request) {
        reloader.stale = propsUnionOf(reloader.stale, request.only);
        reloader.events.onFailed();
    }

    return false;
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
    publish(reloader);
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

            // 古い props をすべて読み直したときだけ添え書きを消す(一部だけでは前の申請者・申請基準日の表示が残る)
            if (reloader.stale.every((prop) => request.only.includes(prop))) {
                reloader.stale = [];
            }

            loaded(reloader, request, page.props as LoadedProps, form);
        },
        onHttpException: () => failed(reloader, mine),
        onNetworkError: () => failed(reloader, mine),
        onFinish: () => {
            settle(reloader, mine);
            publish(reloader);
        },
    });
}

/**
 * 申請者に依る props を読み直したら、サーバーが決めた申請者と申請基準日をフォームへ写す(選択肢に無い申請者なら選択肢も読み直す)。
 * 要求した申請者と別の申請者にサーバーが選び直したときは、申請者を変えたのと同じに扱う(onReselected)。
 */
function loaded(
    reloader: Reloader,
    request: ApplyReloadRequest,
    props: LoadedProps,
    form: ApplyReloadForm,
) {
    const values = loadedApplicantOf(request.only, props);

    if (values === null) {
        return;
    }

    if (values.applicant !== (request.data.applicant ?? null)) {
        reloader.events.onReselected();
    }

    form.setData((data) => ({ ...data, ...values }));
    const stale = staleApplicantsReloadOf(props);

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
    // 失敗で古いままの props も、待っている要求の only と同じに引き継ぐ
    const carried = propsUnionOf(reloader.pending?.only ?? [], reloader.stale);
    const request = applyReloadOf(value, current, carried);

    if (request === null) {
        cancelPending(reloader);
        publish(reloader);
    } else {
        reload(reloader, request, form);
    }
}

/**
 * 申請者を変えたら、選んである他モジュール参照の値を消し、申請者に依る props を引き直す(候補の引き先も新しい申請者になる)。
 * 読み直しが失敗した後は、同じ申請者を選び直しても読み直す(参照の値は消さない)。
 */
function applicantChange(
    reloader: Reloader,
    form: ApplyReloadForm,
    module: WorkflowApplyProps['module'],
    applicant: number | null,
) {
    const isSame = applicant === form.data.applicant;

    if (isSame && reloader.stale.length === 0) {
        return;
    }

    if (!isSame) {
        form.setData((data) => ({
            ...data,
            applicant,
            record: clearRecordReferences(module, data.record),
        }));
    }

    change(reloader, { field: 'applicant', value: applicant }, form);
}

/** 戻る・進むで来た時、次のステップの表示などが欄の申請者・申請基準日で引いたものでなければ読み直す */
function staleReload(
    reloader: Reloader,
    form: ApplyReloadForm,
    loaded: Pick<WorkflowApplyProps, 'applicant' | 'baseDate'>,
) {
    const current = {
        applicant: form.data.applicant,
        baseDate: form.data.base_date,
    };
    const request = staleReloadOf(current, loaded);

    if (request !== null) {
        reload(reloader, request, form);
    }
}

/**
 * 申請の画面の部分の読み直し(docs の 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 5 節、案件の運用/画面/案件の画面.md の 6.1 節)。
 * 送る直前に待っている要求を取り消し、最後に送った要求の応答だけを残す。待っている要求はその要求が終わったら消す(終わった要求の only を
 * 引き継がないため)。戻るで食い違わないよう履歴は積まない(replace)。読み直しの間は events.onStatus の busy、失敗したら onFailed と
 * stale(古い props をすべて読み直す要求が通ったら外す。古い間の要求は古い props も読み直し、同じ値を選び直しても読み直す)。
 * フックの外に置き、送り先を差し替えて確かめられるようにした。
 */
export function applyReloaderOf(
    send: ApplyReloadSend,
    events: Partial<ApplyReloadEvents> = {},
) {
    const reloader: Reloader = {
        send,
        pending: null,
        stale: [],
        events: {
            onReselected: () => {},
            onStatus: () => {},
            onFailed: () => {},
            ...events,
        },
    };

    /** 申請基準日を変えたら next を引き直す(空にしたら、待っている申請者の読み直しがあるときだけ送り直す) */
    const changeBaseDate = (form: ApplyReloadForm, value: string) => {
        form.setData('base_date', value);
        change(reloader, { field: 'base_date', value }, form);
    };

    const changeApplicant = (
        form: ApplyReloadForm,
        module: WorkflowApplyProps['module'],
        applicant: number | null,
    ) => applicantChange(reloader, form, module, applicant);
    const reloadIfStale = (
        form: ApplyReloadForm,
        loaded: Pick<WorkflowApplyProps, 'applicant' | 'baseDate'>,
    ) => staleReload(reloader, form, loaded);

    return { changeBaseDate, changeApplicant, reloadIfStale };
}

/**
 * 申請の画面の部分の読み直し(申請基準日・申請者を変えたとき、戻る・進むで来た時)。送り先は画面を開いたときに 1 回だけ決める。
 * restored = 戻る・進むで来た(覚えた入力で描いた)。
 */
export function useApplyReload(
    form: InertiaFormProps<ApplyForm>,
    props: WorkflowApplyProps,
    events: ApplyReloadEvents,
    restored: boolean,
) {
    // 読み直しの応答は描き直しの後に届くので、呼ぶ先は最新の描画のものにする。
    const latest = useRef(events);

    useEffect(() => {
        latest.current = events;
    });

    const [reloader] = useState(() =>
        applyReloaderOf((options) => router.reload(options), {
            onReselected: () => latest.current.onReselected(),
            onStatus: (status) => latest.current.onStatus(status),
            onFailed: () => latest.current.onFailed(),
        }),
    );

    useEffect(() => {
        if (restored) {
            reloader.reloadIfStale(form, props);
        }
        // 戻る・進むで来た直後の 1 回だけ(その時の props と覚えた入力を比べる)
    }, []);

    return {
        changeBaseDate: (value: string) => reloader.changeBaseDate(form, value),
        changeApplicant: (applicant: number | null) =>
            reloader.changeApplicant(form, props.module, applicant),
    };
}
