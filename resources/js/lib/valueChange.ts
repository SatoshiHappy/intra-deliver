import { isEmptyRecordValue } from '@/lib/recordValues';
import type { RecordFieldValue } from '@/types';

// 値の変化(前 → 後)の見せ方をアプリで 1 か所にする(docs の 設計/画面の意匠/README.md の採った方針 15)。
// 色だけに頼らないよう、前と後を字の飾りでも分ける。

export const VALUE_BEFORE_CLASS = 'text-muted-foreground line-through';

export const VALUE_AFTER_CLASS = 'font-bold text-primary';

export const UNSET_VALUE = '未設定';

/** 複数行の前・後に出す文(文字列でない値は来ない前提で、空と同じに扱う) */
export function multilineTextOf(value: RecordFieldValue): string {
    return isEmptyRecordValue(value) || typeof value !== 'string'
        ? UNSET_VALUE
        : value;
}
