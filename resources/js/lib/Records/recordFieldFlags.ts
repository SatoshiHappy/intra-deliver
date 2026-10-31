import { canGrantRowAccess } from '@/lib/Records/recordDefinitions';
import type { RecordFieldType } from '@/types';

/**
 * 項目の追加・編集の右の枠「属性」の旗(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.4)。型ごとの出し分けと並び、送る形を 1 か所に置く。
 */

/** 旗の欄の値のキー(フォームの値) */
type FlagKey =
    | 'isRequired'
    | 'isListed'
    | 'isSearchable'
    | 'isTitle'
    | 'grantsRowAccess';

/** 旗の欄の値(フォームの値 = lib/Records/recordFieldForm.ts の FieldFormData のうち、旗と型) */
type FlagData = {
    type: RecordFieldType | '';
    isMultiline: boolean;
} & Record<FlagKey, boolean>;

/** 旗 1 つ(inputKey = 業務処理の入力のキー = 誤りのキー、hint = チェックの下の説明) */
export type AttributeFlag = {
    key: FlagKey;
    inputKey: string;
    label: string;
    hint: string | null;
};

/** 「関係者」の説明(設計/業務データの入れ物/画面/管理の画面.md の 3.4) */
const GRANTS_ROW_ACCESS_HINT =
    'この項目に入っている人(組織は所属する人)を、レコードの関係者にします。関係者は、参照を制限したレコードを表示でき、業務モジュールの通知の宛先になります。';

/**
 * 型に応じて出す旗(並び = 必須・一覧に表示・検索条件にする・表示の項目・関係者)。必須は真偽で出さず、明細は必須だけ、
 * 表示の項目は 1 行の文字列だけ、関係者はユーザー・組織・ユーザーと組織(1 つと複数)だけ。
 */
export function attributeFlags(
    data: Pick<FlagData, 'type' | 'isMultiline'>,
): AttributeFlag[] {
    const { type } = data;
    const flag = (
        key: FlagKey,
        inputKey: string,
        label: string,
        hint: string | null = null,
    ): AttributeFlag => ({ key, inputKey, label, hint });

    return [
        type === 'boolean'
            ? null
            : flag(
                  'isRequired',
                  'is_required',
                  type === 'details' ? '必須(1 行以上)' : '必須',
              ),
        type === 'details' ? null : flag('isListed', 'is_listed', '一覧に表示'),
        type === 'details'
            ? null
            : flag('isSearchable', 'is_searchable', '検索条件にする'),
        type === 'string' && !data.isMultiline
            ? flag('isTitle', 'is_title', '表示の項目')
            : null,
        canGrantRowAccess(type)
            ? flag(
                  'grantsRowAccess',
                  'grants_row_access',
                  '関係者',
                  GRANTS_ROW_ACCESS_HINT,
              )
            : null,
    ].filter((item) => item !== null);
}

/**
 * 旗の業務処理の入力(型に関係しないキーは送らない = 3.4)。真偽は必須を送らず、明細と型なしは一覧・検索を送らない。
 * 文字列は表示の項目を送り(複数行は偽)、関係者はユーザー・組織・ユーザーと組織だけ送る(追加でも編集でも = 変えられる)。
 */
export function flagsPayload(data: FlagData): Record<string, boolean> {
    const { type } = data;

    return {
        ...(type === 'boolean' ? {} : { is_required: data.isRequired }),
        ...(type === 'string'
            ? { is_title: data.isMultiline ? false : data.isTitle }
            : {}),
        ...(type === 'details' || type === ''
            ? {}
            : { is_listed: data.isListed, is_searchable: data.isSearchable }),
        ...(canGrantRowAccess(type)
            ? { grants_row_access: data.grantsRowAccess }
            : {}),
    };
}
