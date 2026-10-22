import type { RecordAddonAction } from '@/types';

/**
 * 会議のダイアログに共通の値の読み方と開閉の守り(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.2・9.3)。
 */

/** 上限の単位(割り切れる最大の単位で書く。サーバーの上限の文と同じ書き方) */
const SIZE_UNITS: [string, number][] = [
    ['GB', 1024 ** 3],
    ['MB', 1024 ** 2],
    ['KB', 1024],
];

/**
 * 操作の props の数の値(一覧と詳細の口が渡す上限)。無い・数でないのは口と部品の約束の誤りなので投げる。
 */
export function numberProp(
    values: RecordAddonAction['props'],
    key: string,
): number {
    const value = values[key];

    if (typeof value !== 'number') {
        throw new Error(`会議のダイアログの値 ${key} が数でない`);
    }

    return value;
}

/**
 * 操作の props の真偽の値(詳細の口が渡す書き起こしの有無)。無い・真偽でないのは約束の誤りなので投げる。
 */
export function booleanProp(
    values: RecordAddonAction['props'],
    key: string,
): boolean {
    const value = values[key];

    if (typeof value !== 'boolean') {
        throw new Error(`会議のダイアログの値 ${key} が真偽でない`);
    }

    return value;
}

/**
 * 操作の props の文か null の値(口が渡す先の止め方の文。受けられれば null)。無い・文でも null でもないのは約束の誤りなので投げる。
 */
export function nullableStringProp(
    values: RecordAddonAction['props'],
    key: string,
): string | null {
    const value = values[key];

    if (value !== null && typeof value !== 'string') {
        throw new Error(`会議のダイアログの値 ${key} が文でも null でもない`);
    }

    return value;
}

/**
 * 上限のバイト数の表示(割り切れる最大の単位。例 1,048,576 → 1MB)。
 */
export function limitSizeText(bytes: number): string {
    const unit = SIZE_UNITS.find(([, base]) => bytes % base === 0);

    return unit === undefined ? `${bytes}B` : `${bytes / unit[1]}${unit[0]}`;
}

/**
 * 開閉の知らせを親へ渡すか。送っている間の「閉じる」(Esc・外側のクリック・閉じるボタン・キャンセル)は捨てる
 * (受けた後の取り消しは会議を残すので、送り終わるまで閉じさせない)。
 */
export function acceptsOpenChange(open: boolean, sending: boolean): boolean {
    return open || !sending;
}
