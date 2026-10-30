import { hasPartyKind, partyKindOf } from '@/lib/recordParty';
import { referenceLabel, toTokyoLocal } from '@/lib/recordValues';
import type {
    PickedReference,
    RecordEditable,
    RecordFieldDefinition,
    RecordFieldValue,
    RecordModuleDefinition,
    RecordReferenceValue,
} from '@/types';

/**
 * 業務の登録・編集のフォームの値(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2)。欄の値(下書き)と、送る形への変換。
 * 欄の値: 文字列・整数・小数・日付・日時(datetime-local の YYYY-MM-DDTHH:MM)・選択(コード)= 文字列(空は '')、真偽 = 真偽、
 * ユーザー・組織・ユーザーと組織・他モジュール参照 = 選んだ相手(無ければ null)、複数選べる参照 = 選んだ相手の並び(無ければ null か空)。
 * 明細は行の配列(key は画面の中だけの識別子で、送らない)。
 */

/** 1 つの欄の値 */
export type RecordFieldDraft =
    | string
    | boolean
    | PickedReference
    | PickedReference[]
    | null;

/** 明細の 1 行(id = 既にある行のレコードの id。新しい行は null) */
export type RecordRowDraft = {
    key: string;
    id: number | null;
    values: Record<string, RecordFieldDraft>;
};

/** フォームの値(useForm の data) */
export type RecordFormData = {
    values: Record<string, RecordFieldDraft>;
    details: Record<string, RecordRowDraft[]>;
};

/** 明細の項目 1 つの行の数の上限(項目と規則/README.md 6 節) */
export const MAX_DETAIL_ROWS = 100;

/** 複数選べる参照の相手の数の上限(項目と規則/README.md 5 節) */
export const MAX_REFERENCE_TARGETS = 100;

/** 子の業務モジュールを持つ明細の項目 */
type DetailsField = RecordFieldDefinition & {
    childModule: RecordModuleDefinition;
};

/** 明細の表として描く項目。描く側(RecordFields)と shownRecordKeys が同じ判定を使う(子の業務モジュールが無い明細は描かない) */
export function drawnDetailsFields(
    module: RecordModuleDefinition,
): DetailsField[] {
    return module.fields.filter(
        (field): field is DetailsField =>
            field.type === 'details' && field.childModule !== null,
    );
}

/** 新しい行の key の連番(画面の中だけ) */
let nextRowKey = 0;

/**
 * 参照の項目か(ユーザー・組織・ユーザーと組織・他モジュール参照)。
 */
export function isReferenceField(field: RecordFieldDefinition): boolean {
    return ['user', 'department', 'user_or_department', 'record'].includes(
        field.type,
    );
}

/**
 * 格子の中で 2 列分を使う項目(複数行の文字列。画面の意匠の README 方針 9 (c))。登録・編集の画面と詳細の画面で同じ。
 */
export function isWideField(field: RecordFieldDefinition): boolean {
    return field.type === 'string' && field.isMultiline;
}

/**
 * 通常・参照の項目(明細を除く。項目の並び)。
 */
export function valueFields(
    module: RecordModuleDefinition,
): RecordFieldDefinition[] {
    return module.fields.filter((field) => field.type !== 'details');
}

/**
 * 既定値の欄の値(真偽は既定値か偽。日時は Asia/Tokyo に直す。参照は既定値を持たない = 項目と規則/README.md 3 節)。
 */
function defaultDraft(field: RecordFieldDefinition): RecordFieldDraft {
    const value = field.defaultValue;

    if (field.type === 'boolean') {
        return value === true;
    }

    if (isReferenceField(field)) {
        return null;
    }

    if (value === null || typeof value === 'boolean') {
        return '';
    }

    return field.type === 'datetime'
        ? (toTokyoLocal(String(value)) ?? '')
        : String(value);
}

/**
 * 今の値の相手を、選んだ相手にする(表示名 = 画面/README.md 1.3 の参照の表示。所属は今の値の affiliation = 画面/業務の画面.md 2.2。
 * 型「ユーザーと組織」の項目の相手は種類を持つ)。
 *
 * @throws Error 型「ユーザーと組織」の項目の今の値が種類を持たないとき(読み取りの形 = RecordPartyValue に合わない)
 */
function pickedOf(
    field: RecordFieldDefinition,
    target: RecordReferenceValue,
): PickedReference {
    const { id, affiliation } = target;
    const label = referenceLabel(field, target);

    return hasPartyKind(field)
        ? { id, label, affiliation, type: partyKindOf(field, target) }
        : { id, label, affiliation };
}

/**
 * 今の値の欄の値(選択はコード、参照は選んだ状態と表示名、日時は Asia/Tokyo に直す)。規則の条件の値・項目の既定値にも使う。
 */
export function currentDraft(
    field: RecordFieldDefinition,
    value: RecordFieldValue,
): RecordFieldDraft {
    if (field.type === 'boolean') {
        return value === true;
    }

    if (value === null || typeof value === 'boolean') {
        return isReferenceField(field) ? null : '';
    }

    if (Array.isArray(value)) {
        return value.map((target) => pickedOf(field, target));
    }

    if (typeof value === 'object') {
        return 'label' in value ? pickedOf(field, value) : value.code;
    }

    return field.type === 'datetime'
        ? (toTokyoLocal(String(value)) ?? '')
        : String(value);
}

/**
 * 新しい行の key(画面の中だけの連番)。
 */
function nextNewRowKey(): string {
    nextRowKey += 1;

    return `new-${nextRowKey}`;
}

/**
 * 明細の新しい行(初期値は明細のモジュールの既定値)。
 */
export function newRow(child: RecordModuleDefinition): RecordRowDraft {
    return {
        key: nextNewRowKey(),
        id: null,
        values: Object.fromEntries(
            valueFields(child).map((field) => [
                field.code,
                defaultDraft(field),
            ]),
        ),
    };
}

/**
 * 登録のフォームの初期値(既定値。明細は行なし)。
 */
export function createFormData(module: RecordModuleDefinition): RecordFormData {
    return {
        values: Object.fromEntries(
            valueFields(module).map((field) => [
                field.code,
                defaultDraft(field),
            ]),
        ),
        details: Object.fromEntries(
            module.fields
                .filter((field) => field.type === 'details')
                .map((field) => [field.code, []]),
        ),
    };
}

/** 初期値の元の明細の行(id が無い = 新しい行。ワークフローの一時保存の行は id を持たない) */
type InitialRow = { id?: number; values: Record<string, RecordFieldValue> };

/**
 * 編集のフォームの初期値(今の値と、今の明細の行)。ワークフローの申請の画面(一時保存・再申請)も同じ形の値から作る。
 */
export function editFormData(
    module: RecordModuleDefinition,
    record: Pick<RecordEditable, 'values'> & {
        details: Record<string, InitialRow[]>;
    },
): RecordFormData {
    const draftsOf = (
        fields: RecordFieldDefinition[],
        values: Record<string, RecordFieldValue>,
    ) =>
        Object.fromEntries(
            fields.map((field) => [
                field.code,
                currentDraft(field, values[field.code] ?? null),
            ]),
        );

    return {
        values: draftsOf(valueFields(module), record.values),
        details: Object.fromEntries(
            module.fields
                .filter((field) => field.type === 'details')
                .map((field) => [
                    field.code,
                    (record.details[field.code] ?? []).map((row) => ({
                        key:
                            row.id === undefined
                                ? nextNewRowKey()
                                : `id-${row.id}`,
                        id: row.id ?? null,
                        values: draftsOf(
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
