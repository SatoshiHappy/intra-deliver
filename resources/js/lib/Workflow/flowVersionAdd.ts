/**
 * 版を追加するダイアログの日付の計算(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 4 節、
 * 設計/ワークフロー/ルートの深さ/定義の管理.md の 1 節)。日付は YYYY-MM-DD の文字列で、暦の計算は UTC の日付で行う
 * (端末の時刻帯に依らない)。範囲の判定はサーバーが持ち、画面は入力欄の min・max・既定値と「追加時の変更」の文を作る。
 */

const DAY_MS = 86_400_000;

/** 版の開始日として選べる範囲(min = 今日と最後の版の開始日の翌日の遅い方、max = 今日 + 1 年) */
type VersionStartBounds = {
    min: string;
    max: string;
    /** min が max を超えるとき(最後の版の開始日が今日 + 1 年以後)は追加できない */
    canAdd: boolean;
};

function parseDate(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

function formatDate(date: Date): string {
    return date.toISOString().slice(0, 10);
}

/**
 * 日付の n 日後(負なら前)。
 */
function addDays(date: string, days: number): string {
    return formatDate(new Date(parseDate(date).getTime() + days * DAY_MS));
}

/**
 * 日付の 1 年後。2 月 29 日の 1 年後は 2 月 28 日(サーバーの addYearNoOverflow と同じ)。
 */
function addYear(date: string): string {
    const base = parseDate(date);
    const year = base.getUTCFullYear() + 1;
    const month = base.getUTCMonth();
    const lastDay = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();

    return formatDate(
        new Date(Date.UTC(year, month, Math.min(base.getUTCDate(), lastDay))),
    );
}

/**
 * 開始日の範囲。既定値は min。
 */
export function versionStartBounds(
    today: string,
    lastStartDate: string,
): VersionStartBounds {
    const dayAfterLast = addDays(lastStartDate, 1);
    const min = dayAfterLast > today ? dayAfterLast : today;
    const max = addYear(today);

    return { min, max, canAdd: min <= max };
}

/** 日付の入力の形(YYYY-MM-DD で、暦にある日) */
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

function isDate(value: string): boolean {
    return (
        DATE_PATTERN.test(value) &&
        !Number.isNaN(parseDate(value).getTime()) &&
        formatDate(parseDate(value)) === value
    );
}

/**
 * 「追加時の変更」の 1 行(開始日を入れたとき、最後の版の終了日が開始日の前日に変わる。表示は前日の形)。開始日が日付でなければ null。
 */
export function versionChangeText(
    lastNumber: number,
    startDate: string,
): string | null {
    return isDate(startDate)
        ? `版 ${lastNumber} の終了日が ${addDays(startDate, -1)} に変更されます。`
        : null;
}

/**
 * 追加できないときの文(最後の版の開始日が今日から 1 年より先)。
 */
export function versionAddBlockedText(lastNumber: number): string {
    return `版 ${lastNumber} の開始日が今日から 1 年より先のため、版を追加できません。`;
}
