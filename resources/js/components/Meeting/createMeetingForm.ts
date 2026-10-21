import type { RecordFieldDraft, RecordPayloadValue } from '@/lib/recordForm';
import { createFormData, payloadValue } from '@/lib/recordForm';
import type { RecordFieldDefinition, RecordModuleDefinition } from '@/types';

/**
 * 「音声から作成」のダイアログの値と送る形(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.2)。欄は会議の業務モジュールの定義から引き、
 * 入れ物のフォームの値の形(lib/recordForm)で持つ。
 */

/** 取り込むものの札 */
export type MeetingImportSource = 'file' | 'paste';

/** ダイアログの値(取り込みは選んだ札の方だけを送る) */
export type CreateMeetingData = {
    source: MeetingImportSource;
    file: File | null;
    text: string;
    values: Record<string, RecordFieldDraft>;
};

/** 送る形(作成の入口。ファイルは multipart で、ファイルの外の入力 values を JSON の封筒 envelope に入れる) */
export type CreateMeetingPayload =
    | { file: File; envelope: string }
    | { text?: string; values: Record<string, RecordPayloadValue> };

/** 誤りの 1 行(key = 誤りのキー) */
export type MeetingFormError = { key: string; message: string };

/** 件名の欄(任意。空なら AI が付ける) */
export const TITLE_FIELD_CODE = 'title';

/** ダイアログの欄の並び(日時・件名・閲覧者・閲覧組織・プロジェクト・アジェンダ・議事メモ。削除予定日は出さない) */
const FIELD_CODES = [
    'held_at',
    TITLE_FIELD_CODE,
    'viewer_users',
    'viewer_departments',
    'project',
    'agenda',
    'notes',
];

/**
 * ダイアログに出す欄の定義(並びの順。定義に無いコードは出さない)。
 */
export function dialogFields(
    module: RecordModuleDefinition,
): RecordFieldDefinition[] {
    return FIELD_CODES.flatMap((code) =>
        module.fields.filter((field) => field.code === code),
    );
}

/**
 * 開いたときの値(札はファイル、欄は定義の既定値 = 日時は今)。
 */
export function initialCreateMeetingData(
    module: RecordModuleDefinition,
): CreateMeetingData {
    const defaults = createFormData(module).values;

    return {
        source: 'file',
        file: null,
        text: '',
        values: Object.fromEntries(
            dialogFields(module).map((field) => [
                field.code,
                defaults[field.code] ?? null,
            ]),
        ),
    };
}

/**
 * 送る形(選んだ札の取り込みと、ダイアログの欄の値。空の件名は null = AI が付ける)。ファイルの札は multipart になり、文字列の欄は
 * 型を失う(参照の id が数で届かない)ので、欄の値を JSON の封筒にする(docs の 設計/業務ツール/会議/処理.md の 3.2)。
 */
export function createMeetingPayload(
    module: RecordModuleDefinition,
    data: CreateMeetingData,
): CreateMeetingPayload {
    const values = Object.fromEntries(
        dialogFields(module).map((field) => [
            field.code,
            payloadValue(field, data.values[field.code] ?? null),
        ]),
    );

    if (data.source === 'paste') {
        return { text: data.text, values };
    }

    return data.file === null
        ? { values }
        : { file: data.file, envelope: JSON.stringify({ values }) };
}

/**
 * 欄の下に出す誤りのキー(今の札の取り込みの欄と、ダイアログの欄。複数の参照の欄は添字のキーも)。
 */
export function shownErrorKeys(
    module: RecordModuleDefinition,
    data: CreateMeetingData,
): string[] {
    return [
        data.source === 'file' ? 'file' : 'text',
        ...dialogFields(module).flatMap((field) => {
            const key = `values.${field.code}`;
            const draft = data.values[field.code];

            return [
                key,
                ...(Array.isArray(draft)
                    ? draft.map((_, index) => `${key}.${index}`)
                    : []),
            ];
        }),
    ];
}

/**
 * 欄の上に出す誤り(欄の下に出さないキー。キーの順)。項目の値のキー(values.<コード>)には項目の名前を添える
 * (ダイアログに無い項目の誤りを、どの項目か分かるように = 9.2)。
 */
export function topErrors(
    module: RecordModuleDefinition,
    errors: Record<string, string | undefined>,
    shownKeys: string[],
): MeetingFormError[] {
    return Object.keys(errors)
        .filter((key) => !shownKeys.includes(key))
        .sort()
        .flatMap((key) => {
            const message = errors[key];

            return message === undefined
                ? []
                : [{ key, message: withFieldName(module, key, message) }];
        });
}

/**
 * 項目の値のキーなら「項目の名前：文言」、ほかは文言のまま。
 */
function withFieldName(
    module: RecordModuleDefinition,
    key: string,
    message: string,
): string {
    const [head, code] = key.split('.');
    const field =
        head === 'values'
            ? module.fields.find((candidate) => candidate.code === code)
            : undefined;

    return field === undefined ? message : `${field.name}：${message}`;
}
