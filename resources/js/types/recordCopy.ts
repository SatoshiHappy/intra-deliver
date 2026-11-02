import type { RecordFieldValue, RowAccessInput } from './records';

/**
 * 写す時に今は使えない値を空にした欄 1 つ(値を写す 3 つの仕組みで同じ形。サーバーの App\Queries\Records\RecordCopyValues の ClearedField)。
 * field = 親の項目、cell = 明細の行の項目(row = 写した行の添字)、viewers = 参照制限の参照者を外した。
 */
export type ClearedField = {
    kind: 'field' | 'cell' | 'viewers';
    field: string | null;
    detail: string | null;
    row: number | null;
    name: string;
};

/** 複製の登録の画面の初期値(Records/Create の copySource。明細の行は id を持たない = docs の 設計/業務データの入れ物/レコードの共通機能/複製.md の 1 節) */
export type RecordCopySource = {
    id: number;
    values: Record<string, RecordFieldValue>;
    details: Record<string, { values: Record<string, RecordFieldValue> }[]>;
    clearedFields: ClearedField[]; // 今は使えない値を空にした欄(画面の欄の順。無ければ空 = 複製.md の 2 節)
    rowAccess: RowAccessInput; // 元のレコードの値(業務モジュールの既定を使わない = 設計/業務データの入れ物/レコードの共通機能/複製.md の 2 節)
};
