import { currentDraft } from '@/lib/recordForm';
import { partyKindOf } from '@/lib/recordParty';
import type {
    PickedReference,
    RecordFieldDefinition,
    RecordPartyKind,
    RowAccessInput,
} from '@/types';

/**
 * レコードの参照制限の欄の値と送る形(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.2 の「参照制限」)。参照者の欄は
 * 型「ユーザーと組織(複数)」の項目と同じ部品で描き、同じ変換で読み書きするので、その部品に渡す項目の定義をここに 1 つ置く。
 */

/** 参照者の欄の項目の定義(描くのと変換に使わない属性は既定の値) */
export const VIEWERS_FIELD: RecordFieldDefinition = {
    code: 'row_access_viewers',
    name: '参照者',
    type: 'user_or_department',
    isRequired: false,
    isMultiline: false,
    isMultiple: true,
    maxLength: null,
    defaultValue: null,
    options: [],
    referencedModule: null,
    childModule: null,
    isListed: false,
    isSearchable: false,
    isTitle: false,
    grantsRowAccess: false,
};

/** チェックの誤りのキー */
export const RESTRICTED_KEY = 'row_access_restricted';

/** 参照者の誤りのキー(添字の誤りは `row_access_viewers.<添字>`) */
export const VIEWERS_KEY = VIEWERS_FIELD.code;

/** 欄の値(チェックを外しても参照者は保つ) */
export type RowAccessDraft = {
    restricted: boolean;
    viewers: PickedReference[];
};

/** 送る形(参照者はチェックを付けているときだけ。0 個は null) */
export type RowAccessPayload = {
    row_access_restricted: boolean;
    row_access_viewers?: { type: RecordPartyKind; id: number }[] | null;
};

/**
 * 初期値(props の rowAccess)を欄の値にする。
 */
export function rowAccessDraft(input: RowAccessInput): RowAccessDraft {
    const viewers = currentDraft(VIEWERS_FIELD, input.viewers);

    return {
        restricted: input.restricted,
        viewers: Array.isArray(viewers) ? viewers : [],
    };
}

/**
 * 欄の値を送る形にする。
 */
export function rowAccessPayload(draft: RowAccessDraft): RowAccessPayload {
    if (!draft.restricted) {
        return { row_access_restricted: false };
    }

    return {
        row_access_restricted: true,
        row_access_viewers:
            draft.viewers.length === 0
                ? null
                : draft.viewers.map((viewer) => ({
                      type: partyKindOf(VIEWERS_FIELD, viewer),
                      id: viewer.id,
                  })),
    };
}

/**
 * 参照制限の欄の誤りのキーか(チェック・参照者・参照者の添字。フォームの上に回さない)。
 */
export function isRowAccessKey(key: string): boolean {
    return (
        key === RESTRICTED_KEY ||
        key === VIEWERS_KEY ||
        key.startsWith(`${VIEWERS_KEY}.`)
    );
}

/**
 * チェックの表示(詳細の区画と更新履歴の差分 = 画面/業務の画面.md の 2.3、レコードの共通機能/更新履歴.md の 5.2 節)。
 */
export function restrictedText(restricted: boolean): string {
    return restricted ? '制限する' : '制限しない';
}
