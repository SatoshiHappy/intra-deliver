import { applicantValueOf } from '@/lib/Workflow/applyApplicant';
import type { WorkflowApplyProps } from '@/types';

/**
 * 申請の画面の部分の読み直しの要求(申請基準日・申請者を変えたとき。docs の 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 5 節、
 * 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。
 */

/**
 * 申請者を変えたときに読み直す props(申請者で引く定義・次の処理担当者・申請基準日とその範囲と申請者・付いている添付。付いている添付は
 * 申請者がレコードを閲覧できるかで決まる)。申請者の選択肢は画面と経路で決まり、
 * 選んだ申請者に依らないので読み直さない(申請対象から開いた画面で、新規の申請の選択肢に入れ替わらないように)。
 */
const APPLICANT_PROPS = [
    'module',
    'next',
    'baseDate',
    'baseDateRange',
    'applicant',
    'attachment',
];

/** 申請者・申請基準日の読み直しの失敗のトースト(文の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 8 節) */
export const NEXT_FAILED_MESSAGE =
    '次のステップを読み込めませんでした。時間をおいて、もう一度選択してください。';

/** 読み直しの失敗の後の、次のステップの表示の上の添え書き(次に読み直しが通ったら消す。文の正本 = 同じ 8 節) */
export const NEXT_STALE_NOTE = '変更前の申請者・申請基準日での表示です。';

/** 申請基準日を変えたときに読み直す props(baseDate = next を引いた日。戻る・進むで来た時に、next が欄の日付で引いたものかを比べる) */
const BASE_DATE_PROPS = ['next', 'baseDate'];

/** 部分の読み直しの要求(クエリと読み直す props) */
export type ApplyReloadRequest = {
    data: Record<string, string | number | undefined>;
    only: string[];
};

/** 読み直しの状態(busy = 読み直しの間。申請・一時保存を押せない。stale = 最後の読み直しが失敗し、次のステップの表示が前の値のもの) */
export type ApplyReloadStatus = { busy: boolean; stale: boolean };

/** 読み直しの状態を画面へ知らせる口(onFailed = 読み直しの失敗のトースト) */
export type ApplyReloadEvents = {
    onReselected: () => void;
    onStatus: (status: ApplyReloadStatus) => void;
    onFailed: () => void;
};

/** 読み直しを起こした入力の変更(値は変えた後の値) */
export type ApplyReloadChange =
    | { field: 'base_date'; value: string }
    | { field: 'applicant'; value: number | null };

/**
 * 部分の読み直しの要求。どの要求も今の入力の全部をクエリに載せ、引き継ぐ props を only に足す(取り消した要求が運ぶはずだった値と props、
 * 失敗で古いままの props を失わないため)。送らないときは null(待っている要求を取り消すだけ)。
 * current = 変えていない方の今の入力(フォームの値)、carried = 待っている要求の only と失敗で古いままの props の和(無ければ空)。
 */
export function applyReloadOf(
    change: ApplyReloadChange,
    current: { applicant: number | null; baseDate: string },
    carried: string[],
): ApplyReloadRequest | null {
    const own = ownPropsOf(change);

    if (own.length === 0 && !carried.includes('applicant')) {
        return null;
    }

    const applicant =
        change.field === 'applicant' ? change.value : current.applicant;
    const baseDate =
        change.field === 'base_date' ? change.value : current.baseDate;
    const only = propsUnionOf(own, carried);

    return requestOf(applicant, baseDate, only);
}

/**
 * 申請者に依る props を読み直した応答から、フォームへ写す値(サーバーが決めた申請者と申請基準日が正 = 案件の画面.md の 6.1 節の既定の申請者)。
 * 申請者に依る props を読み直していない要求は null。
 */
export function loadedApplicantOf(
    only: string[],
    loaded: Pick<WorkflowApplyProps, 'applicant' | 'baseDate'>,
): { applicant: number | null; base_date: string } | null {
    return only.includes('applicant')
        ? { applicant: loaded.applicant, base_date: loaded.baseDate }
        : null;
}

/**
 * 読み直しが返した申請者が今の選択肢に無いとき(開いた後に代理の設定が変わった)、選択肢も読み直す要求。要らなければ null。
 * only を付けるのは、読み直す props を申請者に依るものと選択肢に限るため(targetEntry は useApplyOpened が開いた時のまま保つ)。
 */
export function staleApplicantsReloadOf(
    loaded: Pick<WorkflowApplyProps, 'applicants' | 'applicant' | 'baseDate'>,
): ApplyReloadRequest | null {
    if (applicantValueOf(loaded.applicants, loaded.applicant) !== '') {
        return null;
    }

    const only = [...APPLICANT_PROPS, 'applicants'];

    return requestOf(loaded.applicant, loaded.baseDate, only);
}

/**
 * 戻る・進むで来た時の読み直しの要求(次のステップの表示などが、欄の申請者・申請基準日で引いたものでなければ。docs の
 * 設計/ワークフロー/申請の周辺/画面.md の 5 節の「戻る・進むと申請対象の写し」)。loaded = 今の props が引いた申請者と申請基準日。
 * 空の申請基準日は比べない(空のときは読み直さない = 変えた直後と同じ)。要らなければ null。
 */
export function staleReloadOf(
    current: { applicant: number | null; baseDate: string },
    loaded: Pick<WorkflowApplyProps, 'applicant' | 'baseDate'>,
): ApplyReloadRequest | null {
    if (current.applicant !== loaded.applicant) {
        return requestOf(current.applicant, current.baseDate, APPLICANT_PROPS);
    }

    return current.baseDate !== '' && current.baseDate !== loaded.baseDate
        ? requestOf(current.applicant, current.baseDate, BASE_DATE_PROPS)
        : null;
}

/** 2 つの props の並びの和(前の並びの順のまま、後の並びに足す分を後ろへ) */
export function propsUnionOf(a: string[], b: string[]): string[] {
    return [...a, ...b.filter((prop) => !a.includes(prop))];
}

/** 変えた入力で読み直す props(申請基準日を空にしたときは無い) */
function ownPropsOf(change: ApplyReloadChange): string[] {
    if (change.field === 'applicant') {
        return APPLICANT_PROPS;
    }

    return change.value === '' ? [] : BASE_DATE_PROPS;
}

/**
 * クエリ applicant は自分なら外し、空の申請基準日は外す。開いた後の URL に target は無い(開いた直後に外す)ので、部分の読み直しは
 * target の無い GET になる(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節)。
 */
function requestOf(
    applicant: number | null,
    baseDate: string,
    only: string[],
): ApplyReloadRequest {
    return {
        data: {
            applicant: applicant ?? undefined,
            base_date: baseDate === '' ? undefined : baseDate,
        },
        only,
    };
}
