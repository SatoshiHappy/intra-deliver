import type { RecordFieldType } from '@/types';

/**
 * 日付・日時の項目の相対の既定値「登録の時から <期間> 後」の画面の語と形(docs の 設計/業務データの入れ物/項目と規則/README.md 3 節、
 * 設計/業務データの入れ物/画面/管理の画面.md 3.3・3.4)。長さ duration は ISO 8601 の形の文字列。
 */

/** 長さの単位(日・か月・年) */
export type RelativeUnit = 'D' | 'M' | 'Y';

/** 単位の画面の語(並びは選択の順) */
export const RELATIVE_UNIT_LABELS: Record<RelativeUnit, string> = {
    D: '日',
    M: 'か月',
    Y: '年',
};

/** 日付・日時の既定値の持ち方(指定しない・固定の値・登録の時から) */
export type DefaultMode = 'none' | 'fixed' | 'relative';

/** 持ち方の画面の語(並びは選択の順) */
export const DEFAULT_MODE_LABELS: Record<DefaultMode, string> = {
    none: '指定しない',
    fixed: '固定の値',
    relative: '登録の時から',
};

/**
 * 相対の既定値を持てる型か(日付・日時)。
 */
export function hasRelativeDefault(type: RecordFieldType | ''): boolean {
    return type === 'date' || type === 'datetime';
}

/**
 * 長さの文字列を数と単位にする(形が違えば null)。
 */
export function parseDuration(
    duration: string,
): { amount: string; unit: RelativeUnit } | null {
    const parts = /^P([0-9]+)([DMY])$/.exec(duration);

    return parts === null
        ? null
        : { amount: parts[1], unit: parts[2] as RelativeUnit };
}

/**
 * 数の欄と単位から送る値を作る(数の欄の誤りは画面で止めず、送った後の 422 で出す)。
 */
export function relativePayload(
    amount: string,
    unit: RelativeUnit,
): { relative: string } {
    return { relative: `P${amount.trim()}${unit}` };
}

/**
 * 項目の表の既定値の札の文(例「登録の時から 6 か月後」)。
 */
export function relativeDefaultText(duration: string): string {
    const parsed = parseDuration(duration);

    return parsed === null
        ? `登録の時から ${duration} 後`
        : `登録の時から ${parsed.amount} ${RELATIVE_UNIT_LABELS[parsed.unit]}後`;
}
