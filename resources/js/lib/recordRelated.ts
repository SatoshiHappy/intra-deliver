import type { RecordFormData } from '@/lib/recordForm';
import { createFormData, currentDraft } from '@/lib/recordForm';
import { toTokyoLocal } from '@/lib/recordValues';
import type {
    RecordFieldValue,
    RecordRelatedEntry,
    RecordRelatedForm,
} from '@/types';

/**
 * 関連レコード(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md)の画面の分岐。クエリの値は入口のキーの一覧で照らしてから使う
 * (描画にそのまま使わない = 詳細の画面/README.md の 9 節)。
 */

/** 左のタブ「関連レコード」のキー(クエリ tab の値 = 詳細の画面/README.md の 3 節) */
export const RELATED_TAB = 'related';

/** 1 つの入口に選べる列の数(関連レコード.md の 2 節) */
export const MAX_RELATED_COLUMNS = 5;

/**
 * 選んでいる入口のキー(クエリ related が入口のキーのどれかならそれ、ほかは先頭の入口。入口が無ければ null = 3 節)。
 */
export function selectedRelatedKey(url: string, keys: string[]): string | null {
    const key = new URL(url, 'http://localhost').searchParams.get('related');

    return key !== null && keys.includes(key) ? key : (keys[0] ?? null);
}

/**
 * タブの件数(見える入口の参照元の件数の和。0 も出す = 3 節)。
 */
export function relatedTotal(entries: RecordRelatedEntry[]): number {
    return entries.reduce((sum, entry) => sum + entry.count, 0);
}

/**
 * 表の行の先頭の列のリンクの文字を値で出すか(値が空なら #<id> を出す = 2 節)。
 */
export function hasLinkValue(value: RecordFieldValue): boolean {
    return (
        value !== null &&
        value !== '' &&
        !(Array.isArray(value) && value.length === 0)
    );
}

/**
 * 表の「更新日」(更新日時の Asia/Tokyo の日付 YYYY/MM/DD。読めなければそのまま = 2 節)。
 */
export function updatedDate(value: string): string {
    return toTokyoLocal(value)?.slice(0, 10).replaceAll('-', '/') ?? value;
}

/**
 * 「新規」のダイアログの欄の初期値(既定値。入口の項目はこのレコードで固定 = 4.2 節)。
 */
export function relatedFormData(form: RecordRelatedForm): RecordFormData {
    const data = createFormData(form.module);
    const field = form.module.fields.find((item) => item.code === form.field);

    return field === undefined
        ? data
        : {
              ...data,
              values: {
                  ...data.values,
                  [field.code]: currentDraft(field, form.value),
              },
          };
}

/** 「新規」のダイアログの中身の状態(読み込み中・読み込めなかった・フォーム) */
export type RelatedDialogStatus = 'loading' | 'failed' | 'ready';

/**
 * 「新規」のダイアログの中身の状態。フォームが届けばフォーム、届かないまま要求が終わった(失敗・取り消し・出せない入口で null)なら
 * 読み込めなかった、まだ終わっていなければ読み込み中。
 */
export function relatedDialogStatus(
    form: RecordRelatedForm | null,
    finished: boolean,
): RelatedDialogStatus {
    if (form !== null) {
        return 'ready';
    }

    return finished ? 'failed' : 'loading';
}

/**
 * ダイアログを開いてから登録した数の 1 行(4.2 節)。
 */
export function addedMessage(count: number): string {
    return `${count} 件登録しました。`;
}

/**
 * 列の選択の Dialog で、項目のチェックを切り替えた後の選んだ列(外す = 並びから除く、付ける = 末尾に足す。上限を超えては足さない)。
 */
export function toggledColumns(selected: string[], code: string): string[] {
    if (selected.includes(code)) {
        return selected.filter((item) => item !== code);
    }

    return selected.length >= MAX_RELATED_COLUMNS
        ? selected
        : [...selected, code];
}

/**
 * 選んだ列の並びで、index の列を delta(-1 = 上へ、1 = 下へ)動かす(端を越えては動かさない)。
 */
export function movedColumns(
    selected: string[],
    index: number,
    delta: -1 | 1,
): string[] {
    const target = index + delta;

    if (target < 0 || target >= selected.length) {
        return selected;
    }

    const next = [...selected];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
}
