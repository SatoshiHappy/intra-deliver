import type { RecordFieldDefinition } from '@/types';

/**
 * 項目の名前の横の札(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.2): 今の入力で必須なら「必須」、関係者の印があれば
 * 「関係者」の順。入力の欄・明細の表の列の見出し・読み取りの表示(詳細・申請内容)が同じ規則を使う。項目の名前によらず印だけで決める。
 */
export function fieldBadges(
    field: Pick<RecordFieldDefinition, 'grantsRowAccess'>,
    required: boolean,
): string[] {
    return [
        ...(required ? ['必須'] : []),
        ...(field.grantsRowAccess ? ['関係者'] : []),
    ];
}
