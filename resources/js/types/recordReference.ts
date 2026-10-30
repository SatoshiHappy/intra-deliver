/**
 * 参照の値と選んだ相手の画面の型(docs の 設計/業務データの入れ物/画面/README.md 1.2。業務データの値・作成者・更新者・参照の選択欄が使う)。
 */

/**
 * 参照の値(名称・タイトルが無ければ label は null。画面はユーザー・組織はコード、レコードは #<id> を出す)。
 * affiliation = ユーザーの主所属の組織の名前(複数の参照の欄のチップに出す。組織・他モジュール参照と主所属の無いユーザーは null = 画面/README.md 1.2)
 */
export type RecordReferenceValue = {
    id: number;
    code: string | null;
    label: string | null;
    affiliation: string | null;
};

/** 型「ユーザーと組織」の相手の種類(ADR 0028) */
export type RecordPartyKind = 'user' | 'department';

/** 型「ユーザーと組織」の相手 = 相手の種類と、参照の値(ADR 0028。画面/README.md 1.2) */
export type RecordPartyValue = RecordReferenceValue & { type: RecordPartyKind };

/**
 * 参照の選択欄で選んだ相手(id と表示名 = 画面/README.md 4 節)。affiliation = ユーザーの所属(複数の参照の欄のチップに出す)。
 * 出どころは、候補から選んだ相手なら候補の主所属の名前、今の値の相手なら RecordReferenceValue の affiliation(画面/業務の画面.md 2.2)。
 */
export type PickedReference = {
    id: number;
    label: string;
    affiliation?: string | null;
    type?: RecordPartyKind; // 型「ユーザーと組織」の相手だけ。相手は種類と id で区別する
};
