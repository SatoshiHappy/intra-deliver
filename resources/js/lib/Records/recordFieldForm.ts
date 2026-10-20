import type { FieldTypeChoice } from '@/lib/Records/recordDefinitions';
import { canBeMultiple } from '@/lib/Records/recordDefinitions';
import { flagsPayload } from '@/lib/Records/recordFieldFlags';
import type { DefaultValueFormData } from '@/lib/Records/fieldDefaultValue';
import {
    defaultValuePayload,
    emptyDefaultFields,
    initialDefaultFields,
} from '@/lib/Records/fieldDefaultValue';
import type { RecordAdminField } from '@/types';

/**
 * 管理の項目の追加・編集のフォームの値(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.4)と、業務処理の入力への変換。
 * 欄の値: 文字の入力は文字列(空は '')、旗は真偽、既定値は業務のフォームと同じ欄の値(lib/recordForm.ts)、選択肢は行の配列。
 */

/** 選択肢 1 つの欄(isSaved = 作った選択肢。コードは変えられない) */
export type OptionDraft = {
    key: string;
    code: string;
    name: string;
    isSaved: boolean;
};

/** 新しい選択肢の key の連番(画面の中だけ) */
let nextOptionKey = 0;

/**
 * 新しい選択肢の欄。
 */
export function newOption(): OptionDraft {
    nextOptionKey += 1;

    return { key: `new-${nextOptionKey}`, code: '', name: '', isSaved: false };
}

/** フォームの値(型・複数行・最大の文字数・既定値の欄は DefaultValueFormData) */
export type FieldFormData = Omit<DefaultValueFormData, 'options'> & {
    code: string;
    name: string;
    isRequired: boolean;
    isMultiple: boolean;
    options: OptionDraft[];
    referencedModule: string;
    childCode: string;
    childName: string;
    isListed: boolean;
    isSearchable: boolean;
    isTitle: boolean;
    grantsRowAccess: boolean;
};

/**
 * 型の一覧で選んだ選択肢を欄の値にする(型・1 行か複数行か・1 つか複数か。既定値は新しい型の空の欄に戻す)。
 */
export function withTypeChoice(
    data: FieldFormData,
    choice: FieldTypeChoice,
): FieldFormData {
    return {
        ...data,
        type: choice.type,
        isMultiline: choice.isMultiline,
        isMultiple: choice.isMultiple,
        ...emptyDefaultFields(choice.type),
    };
}

/**
 * 追加のフォームの初期値(空)。
 */
function emptyFieldData(): FieldFormData {
    return {
        code: '',
        name: '',
        type: '',
        isRequired: false,
        isMultiline: false,
        isMultiple: false,
        maxLength: '',
        ...emptyDefaultFields(''),
        options: [newOption()],
        referencedModule: '',
        childCode: '',
        childName: '',
        isListed: false,
        // 検索は全項目でできるのが既定(型「明細」では送らない)。要らない項目だけ管理者が外す(画面/管理の画面.md 3.4)
        isSearchable: true,
        isTitle: false,
        grantsRowAccess: false,
    };
}

/**
 * フォームの初期値(追加 = 空、編集 = 今の定義)。
 */
export function initialFieldData(
    field: RecordAdminField | null,
): FieldFormData {
    if (field === null) {
        return emptyFieldData();
    }

    return {
        code: field.code,
        name: field.name,
        type: field.type,
        isRequired: field.isRequired,
        isMultiline: field.isMultiline,
        isMultiple: field.isMultiple,
        maxLength:
            field.maxLengthAttribute === null
                ? ''
                : String(field.maxLengthAttribute),
        ...initialDefaultFields(field),
        options: field.options.map((option) => ({
            key: `saved-${option.code}`,
            code: option.code,
            name: option.name,
            isSaved: true,
        })),
        referencedModule: field.referencedModule?.code ?? '',
        childCode: field.childModule?.code ?? '',
        childName: field.childModule?.name ?? '',
        isListed: field.isListed,
        isSearchable: field.isSearchable,
        isTitle: field.isTitle,
        grantsRowAccess: field.grantsRowAccess,
    };
}

/**
 * 整数の欄の値を送る値にする(空は null、整数の形なら数、ほかは文字のまま送って業務処理の誤りにする)。
 */
function integerPayload(value: string): number | string | null {
    const text = value.trim();

    if (text === '') {
        return null;
    }

    return /^-?[0-9]+$/.test(text) ? Number(text) : text;
}

/**
 * 業務処理の入力(送るキー = 画面/管理の画面.md 3.4)。
 */
export function toFieldPayload(
    data: FieldFormData,
    isNew: boolean,
): Record<string, unknown> {
    const type = data.type;
    const isString = type === 'string';
    const payload: Record<string, unknown> = {
        name: data.name,
        ...(isNew ? { code: data.code, type: type === '' ? null : type } : {}),
        ...flagsPayload(data),
        // 1 つか複数かは追加のときだけ送る(作った後は変えない = 文字列の is_multiline と同じ)
        ...(isNew && canBeMultiple(type)
            ? { is_multiple: data.isMultiple }
            : {}),
        ...(isString
            ? {
                  ...(isNew ? { is_multiline: data.isMultiline } : {}),
                  max_length: integerPayload(data.maxLength),
              }
            : {}),
        ...defaultValuePayload(data),
        ...(type === 'choice'
            ? {
                  options: data.options.map(({ code, name }) => ({
                      code,
                      name,
                  })),
              }
            : {}),
    };

    if (isNew && type === 'record') {
        payload.referenced_module =
            data.referencedModule === '' ? null : data.referencedModule;
    }

    if (isNew && type === 'details') {
        payload.child_module = { code: data.childCode, name: data.childName };
    }

    return payload;
}
