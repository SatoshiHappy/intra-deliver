import type { RecordHold } from '@/types';

/**
 * レコードの持ち主の理由の 1 行(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2・2.3。案件の番号や名前は出さない)。詳細と編集の見出しの下に置く。
 */
export function RecordHoldNotice({ hold }: { hold: RecordHold }) {
    return <p className="-mt-3 text-sm text-muted-foreground">{hold.reason}</p>;
}
