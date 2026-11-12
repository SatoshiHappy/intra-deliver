import {
    confirmOf,
    initialApplicationTargetState,
    isApplicationTargetBusy,
    applicationTargetAnswered,
    applicationTargetPicked,
    applicationTargetSettled,
    targetMarksOf,
} from '@/lib/Workflow/applicationTarget';
import type {
    ApplicationTargetConfirm,
    ApplicationTargetOpening,
    ApplicationTargetState,
} from '@/lib/Workflow/applicationTarget';
import {
    snapshotRecordOf,
    applicationTargetOf,
    withApplicationTarget,
} from '@/lib/Workflow/applicationTargetSnapshot';
import type { ClearedMark } from '@/lib/clearedFields';
import type { RecordFormData } from '@/lib/recordForm';
import type { ApplicationTargetSnapshot } from '@/types/workflowApplyTarget';
import type { PickedReference, WorkflowApplyProps } from '@/types';

/**
 * 申請対象の値の写しの流れ(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」)。画面の描き直しから離して、
 * 外の口(ports)を渡して確かめられるようにした。状態の移りは applicationTarget.ts。
 */

/** 画面に見せる状態(busy = 写しの値を取っている間。confirm = 写す前の確かめ。cleared = 最後に写した値で空にした欄の印) */
export type ApplicationTargetView = {
    busy: boolean;
    confirm: ApplicationTargetConfirm | null;
    cleared: ClearedMark[];
};

/** 取れなかった理由(unavailable = 403・404、failed = ほかの応答の誤りと応答の無い失敗。トーストの文を分ける) */
export type ApplicationTargetFailure = 'unavailable' | 'failed';

/** 写しの値の取り方(取れなければ理由。HTTP の失敗でない例外は reject する) */
export type ApplicationTargetFetch = (
    url: string,
) => Promise<ApplicationTargetSnapshot | ApplicationTargetFailure>;

/** 写しが触る外の口(確かめるときは偽物を渡す) */
export type ApplicationTargetPorts = {
    module: WorkflowApplyProps['module'];
    applicant: number | null;
    /** 応答は描き直しの後に届くので、描画時の値でなく最新の値から替える。前後の値は、申請対象の欄が変わったかを決めるのに使う */
    updateRecord: (update: (record: RecordFormData) => RecordFormData) => {
        before: RecordFormData;
        after: RecordFormData;
    };
    urlOf: (applicationTarget: number, applicant: number | null) => string;
    fetch: ApplicationTargetFetch;
    notifyFailed: (failure: ApplicationTargetFailure) => void;
    publish: (view: ApplicationTargetView) => void;
};

/** 写しの状態(申請対象の欄が無い画面は targetField が null で、何もしない。cleared = 写すたびに置き換える空にした欄の印) */
export type ApplicationTargetFlow = {
    targetField: string | null;
    state: ApplicationTargetState;
    confirm: ApplicationTargetConfirm | null;
    cleared: ClearedMark[];
};

/** 画面に見せる状態を出す */
export function showApplicationTarget(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
) {
    ports.publish({
        busy: isApplicationTargetBusy(applicationTarget.state),
        confirm: applicationTarget.confirm,
        cleared: applicationTarget.cleared,
    });
}

/** 申請対象の欄だけを替える(ほかの欄は変えない) */
function putApplicationTarget(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
    to: PickedReference | null,
) {
    const { targetField } = applicationTarget;

    if (targetField !== null) {
        ports.updateRecord((record) =>
            withApplicationTarget(record, targetField, to),
        );
    }
}

/** 写しの値を当てる(確かめが要らなければ当てて決まり、要れば確かめのダイアログを出す) */
function offer(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
    picked: PickedReference,
    values: ApplicationTargetSnapshot,
) {
    const confirm = confirmOf(
        ports.module,
        applicationTarget.targetField,
        ports.updateRecord((record) => record).after,
        values,
        picked,
    );

    if (confirm === null) {
        const { after } = ports.updateRecord((record) =>
            snapshotRecordOf(ports.module, record, values),
        );
        applicationTarget.state = applicationTargetSettled(
            applicationTarget.state,
            picked,
        );
        applicationTarget.cleared = targetMarksOf(values, after);
    } else {
        applicationTarget.confirm = confirm;
    }
}

/** 応答を受けた(最後に送った要求の応答だけを当てる)。途中で例外が出ても、取得中の印は必ず解く */
function answered(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
    request: number,
    snapshot: ApplicationTargetSnapshot | ApplicationTargetFailure,
) {
    const result = applicationTargetAnswered(
        applicationTarget.state,
        request,
        typeof snapshot === 'string' ? 'failed' : 'ok',
    );
    const { next } = result;
    applicationTarget.state = result.state;

    try {
        if (next.kind === 'snapshot' && typeof snapshot !== 'string') {
            offer(applicationTarget, ports, next.applicationTarget, snapshot);
        } else if (next.kind === 'revert') {
            putApplicationTarget(applicationTarget, ports, next.to);
            ports.notifyFailed(
                typeof snapshot === 'string' ? snapshot : 'failed',
            );
        }
    } finally {
        showApplicationTarget(applicationTarget, ports);
    }
}

/**
 * 写しの値を取り、応答を当てる。例外を投げ直すのは応答を当てた後にして、取得中の印が必ず解けるようにする(docs の
 * 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」)。
 */
async function ask(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
    picked: PickedReference,
    request: number,
) {
    const bug = { thrown: false, error: undefined as unknown };
    let snapshot: ApplicationTargetSnapshot | ApplicationTargetFailure =
        'failed';

    try {
        snapshot = await ports.fetch(ports.urlOf(picked.id, ports.applicant));
    } catch (error) {
        bug.thrown = true;
        bug.error = error;
    }

    answered(applicationTarget, ports, request, snapshot);

    if (bug.thrown) {
        throw bug.error;
    }
}

/**
 * 申請対象の欄が変わった(before・after = 変える前と後のフォームの値)。選んだ・選び直したなら写しの値を取り、空にしたら何も取らない。
 * 返す Promise は応答を当て終わったとき(HTTP の失敗でない例外は reject する)。
 */
async function applicationTargetChanged(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
    before: RecordFormData,
    after: RecordFormData,
) {
    const { targetField } = applicationTarget;
    const picked = applicationTargetOf(after, targetField);

    if (
        targetField === null ||
        picked?.id === applicationTargetOf(before, targetField)?.id
    ) {
        return;
    }

    const result = applicationTargetPicked(applicationTarget.state, picked);
    applicationTarget.state = result.state;
    showApplicationTarget(applicationTarget, ports);

    if (picked !== null && result.request !== null) {
        await ask(applicationTarget, ports, picked, result.request);
    }
}

/** 確かめのダイアログの「反映」(写して、確かめた申請対象が決まる) */
export function reflectApplicationTarget(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
) {
    const { confirm } = applicationTarget;

    if (confirm === null) {
        return;
    }

    const { after } = ports.updateRecord((record) =>
        snapshotRecordOf(ports.module, record, confirm.snapshot),
    );
    applicationTarget.state = applicationTargetSettled(
        applicationTarget.state,
        confirm.applicationTarget,
    );
    applicationTarget.cleared = targetMarksOf(confirm.snapshot, after);
    applicationTarget.confirm = null;
    showApplicationTarget(applicationTarget, ports);
}

/** 確かめのダイアログの「キャンセル」(申請対象も欄の値も変えない) */
export function cancelApplicationTarget(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
) {
    putApplicationTarget(
        applicationTarget,
        ports,
        applicationTarget.state.settled,
    );
    applicationTarget.confirm = null;
    showApplicationTarget(applicationTarget, ports);
}

/** 写しの状態を作る(開いた時の申請対象が最後に決まった申請対象) */
export function applicationTargetFlowOf(
    targetField: string | null,
    opening: ApplicationTargetOpening,
): ApplicationTargetFlow {
    return {
        targetField,
        state: initialApplicationTargetState(
            applicationTargetOf(opening.record, targetField),
        ),
        confirm: opening.confirm,
        cleared: opening.cleared,
    };
}

/**
 * 業務データの欄の更新(update は今の値から次の値を作る関数で、1 回だけ走る)。申請対象の欄が変わったなら写しの値を取る
 * (返す Promise は応答を当て終わったとき)。
 */
export function recordChanged(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
    update: (record: RecordFormData) => RecordFormData,
): Promise<void> {
    const { before, after } = ports.updateRecord(update);

    return applicationTargetChanged(applicationTarget, ports, before, after);
}
