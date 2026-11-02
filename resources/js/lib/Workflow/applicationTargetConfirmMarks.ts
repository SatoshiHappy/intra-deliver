/**
 * 写す前の確かめのダイアログの、今の値と反映する値の見分け(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節のダイアログ)。
 * 色だけに頼らないよう、今の値は薄い字と取り消し線、反映する値は主の色の薄い面(太字にしない)にし、間に → を置く(ユーザーの決定)。
 */
export const CURRENT_VALUE_CLASS =
    'wrap-anywhere whitespace-normal text-muted-foreground line-through';

export const NEXT_VALUE_CLASS = 'wrap-anywhere whitespace-normal bg-secondary';
