import { limitSizeText } from '@/components/Meeting/meetingDialog';
import type { RecordFieldDraft } from '@/lib/recordForm';
import { createFormData } from '@/lib/recordForm';
import type { RecordPayloadValue } from '@/lib/recordPayload';
import { payloadValue } from '@/lib/recordPayload';
import { shownValueKeys } from '@/lib/recordFormErrors';
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

/** ファイルの欄の入力の id(先に止めている音声を選んで送ったとき、フォーカスを移す先) */
export const IMPORT_FILE_ID = 'meeting-import-file';

/** 選べる音声の拡張子(案内の文の並び) */
const AUDIO_EXTENSIONS = ['m4a', 'mp3', 'wav'];

/** 選べるテキストの拡張子 */
const TEXT_EXTENSIONS = ['txt', 'vtt', 'md'];

/** 件名の欄(任意。空なら AI が付ける) */
export const TITLE_FIELD_CODE = 'title';

/** ダイアログの欄の並び(日時・件名・関係者・プロジェクト・アジェンダ・議事メモ。削除予定日は出さない) */
const FIELD_CODES = [
    'held_at',
    TITLE_FIELD_CODE,
    'related_parties',
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
        ...shownValueKeys(dialogFields(module), data.values),
    ];
}

/**
 * 欄の上に出す誤りの文言(値の無いキーは除く)。項目の値のキー(values.<コード>)には「項目の名前：」を添える
 * (ダイアログに無い項目の誤りを、どの項目か分かるように = 9.2)。欄の下の誤りは素の文言のまま出す。
 */
export function namedErrors(
    module: RecordModuleDefinition,
    errors: Record<string, string | undefined>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).flatMap(([key, message]) =>
            message === undefined
                ? []
                : [[key, withFieldName(module, key, message)]],
        ),
    );
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

/** 拡張子に「.」を付ける */
function dotted(extensions: string[]): string[] {
    return extensions.map((extension) => `.${extension}`);
}

/**
 * ファイルの欄の案内(9.2。maxBytes = 音声 1 本の上限。テキストの上限は入口が 422 で知らせる)。
 */
export function importFileGuide(maxBytes: number): string {
    const audio = AUDIO_EXTENSIONS.join('・');
    const text = dotted(TEXT_EXTENSIONS).join('・');

    return `音声(${audio})・テキスト(${text})を 1 つ選択できます。1 ファイル ${limitSizeText(maxBytes)} までです。`;
}

/**
 * ファイルの欄の accept(音声の取り込みを先に止めているときはテキストだけ)。
 */
export function acceptedFiles(audioBlocked: boolean): string {
    const extensions = audioBlocked
        ? TEXT_EXTENSIONS
        : [...AUDIO_EXTENSIONS, ...TEXT_EXTENSIONS];

    return dotted(extensions).join(',');
}

/**
 * 名前の拡張子(大文字小文字を区別しない)が音声か。
 */
function isAudioName(name: string): boolean {
    const dot = name.lastIndexOf('.');

    return (
        dot >= 0 && AUDIO_EXTENSIONS.includes(name.slice(dot + 1).toLowerCase())
    );
}

/**
 * 音声の取り込みを先に止めている(blockedMessage = 先の止め方の文)のに、ファイルの札で音声を選んだときの、ファイルの欄の誤り
 * (9.2。送らない)。ほかは null(テキストと貼り付けは選べる)。
 */
export function blockedFileError(
    data: CreateMeetingData,
    blockedMessage: string | null,
): string | null {
    return blockedMessage !== null &&
        data.source === 'file' &&
        data.file !== null &&
        isAudioName(data.file.name)
        ? blockedMessage
        : null;
}
