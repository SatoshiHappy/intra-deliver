import type { RecordFieldDraft, RecordFormData } from '@/lib/recordForm';
import { valueFields } from '@/lib/recordForm';
import { hasPartyKind, partyKindOf } from '@/lib/recordParty';
import type {
    PickedReference,
    RecordFieldDefinition,
    RecordModuleDefinition,
    RecordPartyKind,
} from '@/types';

/**
 * 業務の登録・編集のフォームの送る形(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2 の「送る形」)。欄の値(lib/recordForm の
 * RecordFieldDraft)を、業務処理の入口に送る値にする。
 */

/** 型「ユーザーと組織」の相手の送る形(種類と id = 画面/業務の画面.md 2.2 の「送る形」) */
type RecordPartyPayload = { type: RecordPartyKind; id: number };

/**
 * 送る値(項目と規則/README.md 2 節の値。参照は id、複数選べる参照は id の配列、型「ユーザーと組織」は種類と id(複数はその配列)、
 * 選択はコード、空は null)
 */
export type RecordPayloadValue =
    | string
    | boolean
    | number
    | number[]
    | RecordPartyPayload
    | RecordPartyPayload[]
    | null;

/** 送る形(画面/業務の画面.md 2.2 の「送る形」) */
export type RecordPayload = {
    values: Record<string, RecordPayloadValue>;
    details: Record<
        string,
        { id?: number; values: Record<string, RecordPayloadValue> }[]
    >;
};

/** 日時の欄の値に付ける秒と時差(Asia/Tokyo。秒は 0 = 画面/業務の画面.md 2.2) */
const DATETIME_SUFFIX = ':00+09:00';

/**
 * 選んだ相手の送る値(型「ユーザーと組織」の項目は種類と id、ほかの参照の型は id)。
 */
function pickedPayload(
    field: RecordFieldDefinition,
    picked: PickedReference,
): number | RecordPartyPayload {
    return hasPartyKind(field)
        ? { type: partyKindOf(field, picked), id: picked.id }
        : picked.id;
}

/**
 * 欄の値を送る値にする(参照は id、複数選べる参照は id の配列で 0 個は null、型「ユーザーと組織」は種類と id、真偽は真偽、
 * 日時は :00+09:00 を付ける、空は null)。規則の条件の値・項目の既定値にも使う。
 */
export function payloadValue(
    field: RecordFieldDefinition,
    draft: RecordFieldDraft,
): RecordPayloadValue {
    if (field.type === 'boolean') {
        return draft === true;
    }

    if (draft === null || draft === '' || typeof draft === 'boolean') {
        return null;
    }

    if (Array.isArray(draft)) {
        if (draft.length === 0) {
            return null;
        }

        return hasPartyKind(field)
            ? draft.map((picked) => ({
                  type: partyKindOf(field, picked),
                  id: picked.id,
              }))
            : draft.map((picked) => picked.id);
    }

    if (typeof draft === 'object') {
        return pickedPayload(field, draft);
    }

    return field.type === 'datetime' ? draft + DATETIME_SUFFIX : draft;
}

/**
 * フォームの値を送る形にする(全項目を送る。明細の行は既にある行だけ id を持つ)。
 */
export function toPayload(
    module: RecordModuleDefinition,
    data: RecordFormData,
): RecordPayload {
    const payloadOf = (
        fields: RecordFieldDefinition[],
        values: Record<string, RecordFieldDraft>,
    ) =>
        Object.fromEntries(
            fields.map((field) => [
                field.code,
                payloadValue(field, values[field.code] ?? null),
            ]),
        );

    return {
        values: payloadOf(valueFields(module), data.values),
        details: Object.fromEntries(
            module.fields
                .filter((field) => field.type === 'details')
                .map((field) => [
                    field.code,
                    (data.details[field.code] ?? []).map((row) => ({
                        ...(row.id === null ? {} : { id: row.id }),
                        values: payloadOf(
                            field.childModule === null
                                ? []
                                : valueFields(field.childModule),
                            row.values,
                        ),
                    })),
                ]),
        ),
    };
}
