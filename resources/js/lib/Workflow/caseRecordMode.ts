import type { WorkflowCaseShowProps } from '@/types';

/** レコードの今の値(recordAccess が真のときの content) */
export type CaseRecordContent = NonNullable<WorkflowCaseShowProps['content']>;

/** 最後の申請・再申請の時点の値の写し(recordAccess が偽のときの submittedRecord) */
export type CaseSubmittedRecord = NonNullable<
    WorkflowCaseShowProps['submittedRecord']
>;

/**
 * 申請の詳細の左のタブとサイドパネルをどの形で描くか(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2・6 節)。
 * 形ごとに描くのに要る値を持たせ、描く側が props の null を確かめ直さないようにする。
 */
export type CaseRecordMode =
    | { kind: 'live'; content: CaseRecordContent }
    | { kind: 'snapshot'; submittedRecord: CaseSubmittedRecord }
    | { kind: 'reload' };

/** 描く形(reload を除く。読み直す間は描かない) */
export type ShownCaseRecordMode = Exclude<CaseRecordMode, { kind: 'reload' }>;

export function caseRecordModeOf(
    props: Pick<
        WorkflowCaseShowProps,
        'recordAccess' | 'content' | 'submittedRecord'
    >,
): CaseRecordMode {
    if (props.recordAccess) {
        return props.content === null
            ? { kind: 'reload' }
            : { kind: 'live', content: props.content };
    }

    return props.submittedRecord === null
        ? { kind: 'reload' }
        : { kind: 'snapshot', submittedRecord: props.submittedRecord };
}

/**
 * 全体を読み直す歩(判定と中身がそろわない間は 1 回だけ読み直し、繰り返さない。そろったら印を戻すので、あとでまたそろわなくなれば
 * もう一度読み直す)。reloaded = 今のずれでもう読み直したか。
 */
export function reloadStepOf(
    stale: boolean,
    reloaded: boolean,
): { reload: boolean; reloaded: boolean } {
    return { reload: stale && !reloaded, reloaded: stale };
}

/**
 * 申請の詳細に描く左右の枠と、処理のボタンの帯の置き場(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の
 * 1 節)。帯はどの形でも出す(読み直しの間も処理のボタンを押せるように)。
 */
export type CaseLayout =
    | { frame: ShownCaseRecordMode; actionBar: 'frameFooter' }
    | { frame: null; actionBar: 'belowHeading' };

export function caseLayoutOf(mode: CaseRecordMode): CaseLayout {
    return mode.kind === 'reload'
        ? { frame: null, actionBar: 'belowHeading' }
        : { frame: mode, actionBar: 'frameFooter' };
}
