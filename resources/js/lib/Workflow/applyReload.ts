import { applicantValueOf } from '@/lib/Workflow/applyApplicant';
import type { WorkflowApplyProps } from '@/types';

/**
 * 申請の画面の部分の読み直しの要求(申請基準日・申請者を変えたとき。docs の 設計/ワークフロー/ルートの深さ/画面.md 5 節、
 * 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。
 */

/**
 * 申請者を変えたときに読み直す props(申請者で引く定義・次の処理担当者・申請基準日とその範囲と申請者)。申請者の選択肢は画面と経路で決まり、
 * 選んだ申請者に依らないので読み直さない(申請対象から開いた画面で、新規の申請の選択肢に入れ替わらないように)。
 */
const APPLICANT_PROPS = [
    'module',
    'next',
    'baseDate',
    'baseDateRange',
    'applicant',
];

/** 申請基準日を変えたときに読み直す props */
const BASE_DATE_PROPS = ['next'];

/** 部分の読み直しの要求(クエリと読み直す props) */
export type ApplyReloadRequest = {
    data: Record<string, string | number | undefined>;
    only: string[];
};

/** 読み直しを起こした入力の変更(値は変えた後の値) */
export type ApplyReloadChange =
    | { field: 'base_date'; value: string }
    | { field: 'applicant'; value: number | null };

/** 画面を開いたときに決まる値(開いたときの申請者と、申請対象から開いた画面か) */
export type ApplyReloadScope = {
    openedApplicant: number | null;
    fromTarget: boolean;
};

/**
 * 部分の読み直しの要求。どの要求も今の入力の全部をクエリに載せ、待っている要求を取り消して送るときはその only を引き継ぐ
 * (取り消した要求が運ぶはずだった値と props を失わないため)。送らないときは null(待っている要求を取り消すだけ)。
 * current = 変えていない方の今の入力(フォームの値)、pendingOnly = 待っている要求の only(無ければ null)。
 */
export function applyReloadOf(
    change: ApplyReloadChange,
    current: { applicant: number | null; baseDate: string },
    pendingOnly: string[] | null,
    scope: ApplyReloadScope,
): ApplyReloadRequest | null {
    const pending = pendingOnly ?? [];
    const own = ownPropsOf(change);

    if (own.length === 0 && !pending.includes('applicant')) {
        return null;
    }

    const applicant =
        change.field === 'applicant' ? change.value : current.applicant;
    const baseDate =
        change.field === 'base_date' ? change.value : current.baseDate;
    const only = [...own, ...pending.filter((prop) => !own.includes(prop))];

    return requestOf(applicant, baseDate, only, scope);
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
 * only を付けたままにするのは、申請対象から開いた画面の targetEntry を開いたときのまま保つため。
 */
export function staleApplicantsReloadOf(
    loaded: Pick<WorkflowApplyProps, 'applicants' | 'applicant' | 'baseDate'>,
    scope: ApplyReloadScope,
): ApplyReloadRequest | null {
    if (applicantValueOf(loaded.applicants, loaded.applicant) !== '') {
        return null;
    }

    const only = [...APPLICANT_PROPS, 'applicants'];

    return requestOf(loaded.applicant, loaded.baseDate, only, scope);
}

/** 変えた入力で読み直す props(申請基準日を空にしたときは無い) */
function ownPropsOf(change: ApplyReloadChange): string[] {
    if (change.field === 'applicant') {
        return APPLICANT_PROPS;
    }

    return change.value === '' ? [] : BASE_DATE_PROPS;
}

/**
 * クエリ applicant は自分なら外し、空の申請基準日は外す。申請対象から開いた画面で申請者が開いたときと違えば、新しい申請者が申請対象を
 * 閲覧できないと 404 になるのでクエリ target を外す(同じなら URL のまま)。
 */
function requestOf(
    applicant: number | null,
    baseDate: string,
    only: string[],
    scope: ApplyReloadScope,
): ApplyReloadRequest {
    const leavesTarget =
        scope.fromTarget && applicant !== scope.openedApplicant;

    return {
        data: {
            applicant: applicant ?? undefined,
            base_date: baseDate === '' ? undefined : baseDate,
            ...(leavesTarget ? { target: undefined } : {}),
        },
        only,
    };
}
