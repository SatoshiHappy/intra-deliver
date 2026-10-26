import { contactPayloadOf } from '@/lib/Workflow/caseActionForm';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import { createFormData, editFormData, toPayload } from '@/lib/recordForm';
import type { RecordFormData } from '@/lib/recordForm';
import type { WorkflowApplyProps } from '@/types';

/**
 * 申請の画面のフォームの値と送る内容(docs の 設計/ワークフロー/画面/申請と案件.md 3 節、案件の運用/画面/案件の画面.md の 6.1 節)。
 */

/**
 * フォームの値(業務データの欄は業務データの入れ物の登録・編集のフォームと同じ形。base_date = 申請基準日、applicant = 選んだ申請者
 * (代理元の id。自分なら null)、contact_users・contact_message = 連絡する人とひと言)。
 */
export type ApplyForm = {
    name: string;
    comment: string;
    base_date: string;
    applicant: number | null;
    record: RecordFormData;
    contact_users: PickedUser[];
    contact_message: string;
};

/** 送る操作(申請 = 申請・一時保存からの申請・再申請、一時保存 = 新規の一時保存・一時保存の上書き) */
export type ApplySendKind = 'apply' | 'saveDraft';

/**
 * 開いた時のフォームの値(新規は既定値、ほかは props の初期値)。
 */
export function initialApplyFormOf({
    module,
    initial,
    baseDate,
    applicant,
}: WorkflowApplyProps): ApplyForm {
    return {
        name: initial?.name ?? '',
        comment: initial?.comment ?? '',
        base_date: baseDate,
        applicant,
        record:
            initial === null
                ? createFormData(module)
                : editFormData(module, initial),
        contact_users: [],
        contact_message: '',
    };
}

/**
 * 申請者の送り先の項目: 新規の申請・新規の一時保存・既存のデータからの申請 = applicant_user_id、再申請 = principal_user_id
 * (どちらも自分なら null)。一時保存の上書き・一時保存からの申請は申請者を送らない(持ち主は変わらない = 案件の操作/操作.md の 10.8 節)。
 */
function applicantFieldsOf(
    mode: WorkflowApplyProps['mode'],
    applicant: number | null,
) {
    if (mode === 'reapply') {
        return { principal_user_id: applicant };
    }

    return mode === 'draft' ? {} : { applicant_user_id: applicant };
}

/**
 * 既存のデータからの申請が送るレコードの指定(record_id・開いたときの版 record_version。版が無いレコードは null のまま送る =
 * 口が空文字にしていつも比べる)。ほかの画面は送らない。
 */
function recordFieldsOf(props: WorkflowApplyProps) {
    return props.mode === 'record'
        ? { record_id: props.recordId, record_version: props.recordVersion }
        : {};
}

/**
 * 送る内容。申請基準日は再申請以外で送る(再申請は申請基準日を変えない = 案件の操作/操作.md の 10.1 節)。連絡は申請と再申請だけで、
 * 人もひと言も空なら送らない(一時保存は連絡を持たない)。
 */
export function applyPayloadOf(
    props: WorkflowApplyProps,
    data: ApplyForm,
    kind: ApplySendKind,
) {
    const contact =
        kind === 'apply'
            ? contactPayloadOf(data.contact_users, data.contact_message)
            : null;

    return {
        name: data.name,
        comment: data.comment,
        ...toPayload(props.module, data.record),
        ...(props.case === null ? {} : { revision: props.case.revision }),
        ...(props.mode === 'reapply' ? {} : { base_date: data.base_date }),
        ...applicantFieldsOf(props.mode, data.applicant),
        ...recordFieldsOf(props),
        ...(contact === null ? {} : { contact }),
    };
}
