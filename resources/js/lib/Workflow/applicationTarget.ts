import {
    snapshotRecordOf,
    needsConfirm,
    applicationTargetChangesOf,
    applicationTargetOf,
} from '@/lib/Workflow/applicationTargetSnapshot';
import type { ApplicationTargetChange } from '@/lib/Workflow/applicationTargetSnapshot';
import type { RecordFormData } from '@/lib/recordForm';
import type { ApplicationTargetSnapshot } from '@/types/workflowApplyTarget';
import type { PickedReference, WorkflowApplyProps } from '@/types';

/**
 * 申請対象の値の写しの状態の移り(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」)。
 * 要求の取り消しは使わず、最後に送った要求の応答だけを当てる(古い応答は番号で捨てる)。
 */

/** 写しの状態(settled = 最後に決まった申請対象、latest = 最後に送った要求の番号、pending = 応答を待っている申請対象) */
export type ApplicationTargetState = {
    settled: PickedReference | null;
    latest: number;
    pending: PickedReference | null;
};

/** 応答の結果(ok = 写しの値、failed = 取れなかった。403・404・500・通信の失敗は同じ扱い) */
export type ApplicationTargetAnswer = 'ok' | 'failed';

/** 応答を受けた後にすること(snapshot = 写しの値を当てる、revert = 申請対象の欄を戻す、ignore = 古い応答なので何もしない) */
export type ApplicationTargetNext =
    | { kind: 'snapshot'; applicationTarget: PickedReference }
    | { kind: 'revert'; to: PickedReference | null }
    | { kind: 'ignore' };

/** 確かめのダイアログに出す内容(applicationTarget = 反映したら決まる申請対象) */
export type ApplicationTargetConfirm = {
    applicationTarget: PickedReference | null;
    snapshot: ApplicationTargetSnapshot;
    changes: ApplicationTargetChange[];
};

/** 開いた時のフォームの値と、開いた直後に確かめるもの */
export type ApplicationTargetOpening = {
    record: RecordFormData;
    confirm: ApplicationTargetConfirm | null;
};

/**
 * 開いた時の状態(開いた時の申請対象が最後に決まった申請対象)。
 */
export function initialApplicationTargetState(
    settled: PickedReference | null,
): ApplicationTargetState {
    return { settled, latest: 0, pending: null };
}

/**
 * 写しの値を取っている間か(この間は申請と一時保存を押せない)。
 */
export function isApplicationTargetBusy(
    state: ApplicationTargetState,
): boolean {
    return state.pending !== null;
}

/**
 * 申請対象の欄を選んだ・選び直した・空にした。選んだなら要求の番号を返す(空にしたら要求は無く、空が最後に決まった申請対象になる)。
 */
export function applicationTargetPicked(
    state: ApplicationTargetState,
    picked: PickedReference | null,
): { state: ApplicationTargetState; request: number | null } {
    if (picked === null) {
        return {
            state: { ...state, settled: null, pending: null },
            request: null,
        };
    }

    const request = state.latest + 1;

    return {
        state: { ...state, latest: request, pending: picked },
        request,
    };
}

/**
 * 申請者を変えて申請対象が消えた(空が最後に決まった申請対象になり、待っている要求の応答は当てない)。
 */
export function applicationTargetCleared(
    state: ApplicationTargetState,
): ApplicationTargetState {
    return { ...state, settled: null, pending: null };
}

/**
 * 応答を受けた(最後に送った要求の応答だけを当てる。docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」)。
 */
export function applicationTargetAnswered(
    state: ApplicationTargetState,
    request: number,
    answer: ApplicationTargetAnswer,
): { state: ApplicationTargetState; next: ApplicationTargetNext } {
    const { pending } = state;

    if (pending === null || request !== state.latest) {
        return { state, next: { kind: 'ignore' } };
    }

    const done = { ...state, pending: null };

    return answer === 'ok'
        ? {
              state: done,
              next: { kind: 'snapshot', applicationTarget: pending },
          }
        : { state: done, next: { kind: 'revert', to: state.settled } };
}

/**
 * 申請対象が決まった(写した・確かめで反映した)。
 */
export function applicationTargetSettled(
    state: ApplicationTargetState,
    applicationTarget: PickedReference | null,
): ApplicationTargetState {
    return { ...state, settled: applicationTarget };
}

/**
 * 写しの値を今のフォームの値に当てる前の確かめ(写す値と違う入力済みの値があるときだけ。無ければ null = そのまま写す)。
 * applicationTarget = 反映したら決まる申請対象。
 */
export function confirmOf(
    module: WorkflowApplyProps['module'],
    targetField: string | null,
    current: RecordFormData,
    snapshot: ApplicationTargetSnapshot,
    applicationTarget: PickedReference | null,
): ApplicationTargetConfirm | null {
    const changes = applicationTargetChangesOf(
        module,
        targetField,
        current,
        snapshot,
    );

    return needsConfirm(changes)
        ? { applicationTarget, snapshot, changes }
        : null;
}

/**
 * 開いた時の写し(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.2 節の「開いた時」の行)。
 * 写しの値を持たない画面(null)は何もしない。
 */
export function openingOf(
    props: WorkflowApplyProps,
    record: RecordFormData,
): ApplicationTargetOpening {
    const { module, targetField, targetValues, targetEntry, mode } = props;

    if (targetValues === null) {
        return { record, confirm: null };
    }

    if (targetEntry === null && mode !== 'record') {
        return { record, confirm: null };
    }

    const confirm =
        targetEntry === null
            ? confirmOf(
                  module,
                  targetField,
                  record,
                  targetValues,
                  applicationTargetOf(record, targetField),
              )
            : null;

    return {
        record:
            confirm === null
                ? snapshotRecordOf(module, record, targetValues)
                : record,
        confirm,
    };
}
