import type { RecordFieldDraft } from '@/lib/recordForm';
import { hasPartyKind, partyKindOf } from '@/lib/recordParty';
import { payloadValue } from '@/lib/recordPayload';
import type {
    PickedReference,
    RecordFieldDefinition,
    RecordFieldValue,
    RecordPartyValue,
    RecordReferenceValue,
} from '@/types';

/**
 * 申請対象の値の写しの確かめのダイアログに出す今の値(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の
 * 10.2 節)。今の欄の値(下書き)を、値の表示(RecordValue)に渡せる値にする。
 */

/** 選んだ相手を、値の表示(RecordValue)に渡せる参照の値にする */
function referenceOf(picked: PickedReference): RecordReferenceValue {
    return {
        id: picked.id,
        code: null,
        label: picked.label,
        affiliation: picked.affiliation ?? null,
    };
}

/**
 * 型「ユーザーと組織」の項目の選んだ相手を、相手の値にする(値の表示が種類の印を付ける)。
 */
function partyOf(
    field: RecordFieldDefinition,
    picked: PickedReference,
): RecordPartyValue {
    return { ...referenceOf(picked), type: partyKindOf(field, picked) };
}

/**
 * 今の欄の値(下書き)を、値の表示に渡せる値にする。空の扱いは「入力済みか」の判定と同じ(payloadValue)で決める。
 */
export function shownValueOf(
    field: RecordFieldDefinition,
    draft: RecordFieldDraft,
): RecordFieldValue {
    const payload = payloadValue(field, draft);

    if (field.type === 'boolean') {
        return payload === true;
    }

    if (payload === null) {
        return null;
    }

    // 型「ユーザーと組織」の項目は相手の値、ほかの参照の型は参照の値(送る形と同じく項目の型で決める = lib/recordParty の hasPartyKind)
    if (Array.isArray(draft)) {
        return hasPartyKind(field)
            ? draft.map((picked) => partyOf(field, picked))
            : draft.map(referenceOf);
    }

    if (typeof draft === 'object' && draft !== null) {
        return hasPartyKind(field) ? partyOf(field, draft) : referenceOf(draft);
    }

    // 参照(数・相手)と真偽は上で返したので、送る値は文字列(選択はコード、日時は時差つき)
    if (typeof payload !== 'string') {
        return null;
    }

    if (field.type === 'choice') {
        const option = field.options.find((choice) => choice.code === payload);

        return { code: payload, name: option?.name ?? null };
    }

    return payload;
}
