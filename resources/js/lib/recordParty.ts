import type {
    RecordFieldDefinition,
    RecordFieldValue,
    RecordPartyKind,
    RecordReferenceValue,
} from '@/types';

/**
 * 型「ユーザーと組織」の相手の種類の判定(docs の 設計/業務データの入れ物/画面/README.md 1.2 の RecordPartyValue、1.3 の
 * 「ユーザーと組織が混ざりうる値」)。相手の形(送る形・検索条件の値・表示の種類の印と名前・候補の群)は、相手の値でなく項目の型で決め、
 * その判定をここに 1 つ置く。表示の相手の並び(partiesOf)も項目の型で決めるので、ここに置く。
 */

/** 型「ユーザーと組織」の相手の種類の名前(名前の無い相手の表示と、種類の印の読み上げ) */
export const PARTY_KIND_NAMES: Record<RecordPartyKind, string> = {
    user: 'ユーザー',
    department: '組織',
};

/** 種類を判定する項目(型と、誤りの文のコード) */
type PartyField = Pick<RecordFieldDefinition, 'type' | 'code'>;

/**
 * 項目が相手の種類を持つ型か(型「ユーザーと組織」。サーバーの RecordFieldType::hasPartyKind() に当たる)。
 */
export function hasPartyKind(field: PartyField): boolean {
    return field.type === 'user_or_department';
}

/**
 * 型「ユーザーと組織」の項目の相手(選んだ相手 PickedReference・参照の値 RecordPartyValue)の種類。種類の無い相手は起きない
 * (候補の口・今の値の読み取り・サーバーの読み取りの形が必ず付ける)ので、名前の有無によらず投げる(黙って種類の無い形に変えない)。
 *
 * @throws Error 相手が種類を持たないとき(呼び手の誤り)
 */
export function partyKindOf(
    field: PartyField,
    party: { id: number; type?: RecordPartyKind },
): RecordPartyKind {
    if (party.type === undefined) {
        throw new Error(
            `型「ユーザーと組織」の項目 ${field.code} の相手 ${party.id} が種類を持たない`,
        );
    }

    return party.type;
}

/**
 * 型「ユーザーと組織」の項目の値の相手(1 つでも複数でも並び。値の表示が相手ごとに種類の印を付ける)。ほかの型の項目と未設定・空の並びは
 * null(項目の型で決める)。参照の値でない要素は起きない(読み取りの形 = RecordPartyValue)ので投げる(黙って落とさない。
 * 種類の無い参照の値は partyKindOf が投げる)。
 *
 * @throws Error 型「ユーザーと組織」の項目の値に参照の値でない要素があるとき
 */
export function partiesOf(
    field: PartyField,
    value: RecordFieldValue,
): RecordReferenceValue[] | null {
    if (!hasPartyKind(field) || value === null) {
        return null;
    }

    const values: RecordFieldValue[] = Array.isArray(value) ? value : [value];

    return values.length === 0
        ? null
        : values.map((item) => {
              if (
                  typeof item !== 'object' ||
                  item === null ||
                  !('label' in item)
              ) {
                  throw new Error(
                      `型「ユーザーと組織」の項目 ${field.code} の値に相手の形でない要素がある`,
                  );
              }

              return item;
          });
}
