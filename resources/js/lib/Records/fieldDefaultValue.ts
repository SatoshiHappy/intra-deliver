import type { DefaultMode, RelativeUnit } from '@/lib/Records/relativeDefault';
import {
    hasRelativeDefault,
    parseDuration,
    relativePayload,
} from '@/lib/Records/relativeDefault';
import type { RecordFieldDraft } from '@/lib/recordForm';
import { currentDraft, payloadValue } from '@/lib/recordForm';
import type {
    RecordAdminField,
    RecordFieldDefinition,
    RecordFieldType,
    StringMaxLengths,
} from '@/types';

/**
 * 管理の項目の追加・編集のフォームの既定値の欄と最大の文字数の説明(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.4)。
 * 日付・日時は既定値の持ち方(指定しない・固定の値・登録の時から)を選ぶ(項目と規則/README.md 3 節の相対の既定値)。
 */

/** 既定値の欄に関わるフォームの値(フォームの値 FieldFormData の一部) */
export type DefaultValueFormData = {
    type: RecordFieldType | '';
    isMultiline: boolean;
    maxLength: string;
    options: { code: string; name: string }[];
    defaultValue: RecordFieldDraft;
    /** 日付・日時の既定値の持ち方と、「登録の時から」の数・単位(ほかの型では使わない) */
    defaultMode: DefaultMode;
    relativeAmount: string;
    relativeUnit: RelativeUnit;
};

/** 既定値の欄の値(初期値と、欄で変える値) */
export type DefaultValueFields = Pick<
    DefaultValueFormData,
    'defaultValue' | 'defaultMode' | 'relativeAmount' | 'relativeUnit'
>;

/** 既定値を持てる型(項目と規則/README.md 1 節の表) */
const PLAIN_TYPES: RecordFieldType[] = [
    'string',
    'integer',
    'decimal',
    'date',
    'datetime',
    'boolean',
    'choice',
];

/**
 * 既定値を持てる型か。
 */
function hasDefaultValue(type: RecordFieldType | ''): boolean {
    return type !== '' && PLAIN_TYPES.includes(type);
}

/**
 * 既定値を送る値にするための項目の定義(今の欄の型・複数行・選択肢から作る)。
 */
function defaultValueProbe(
    data: DefaultValueFormData,
): RecordFieldDefinition | null {
    if (data.type === '' || !hasDefaultValue(data.type)) {
        return null;
    }

    return {
        code: 'default_value',
        name: '既定値',
        type: data.type,
        isRequired: false,
        isMultiline: data.isMultiline,
        isMultiple: false,
        maxLength: null,
        defaultValue: null,
        // 編集中の同じコードの行は先の行だけ(選択欄の候補の鍵を一意に。重なりは送ると選択肢の表の検証が拒む)
        options: data.options
            .filter(
                (option, index, all) =>
                    option.code !== '' &&
                    all.findIndex((other) => other.code === option.code) ===
                        index,
            )
            .map(({ code, name }) => ({
                code,
                name: name === '' ? code : name,
            })),
        referencedModule: null,
        childModule: null,
        isListed: false,
        isSearchable: false,
        isTitle: false,
        grantsRowAccess: false,
    };
}

/**
 * 既定値の欄を描くための項目の定義。文字列の上限は編集中の最大の文字数(指定できる範囲 = 画面/管理の画面.md 3.4。
 * 範囲の外は型の上限にし、範囲の誤りは max_length の 422 が知らせる)。
 */
export function defaultValueField(
    data: DefaultValueFormData,
    stringMaxLengths: StringMaxLengths,
): RecordFieldDefinition | null {
    const probe = defaultValueProbe(data);

    if (probe === null || probe.type !== 'string') {
        return probe;
    }

    const typeMax = data.isMultiline
        ? stringMaxLengths.multiline
        : stringMaxLengths.singleLine;
    const limit = data.isMultiline
        ? stringMaxLengths.multilineMax
        : stringMaxLengths.singleLine;
    const attribute = data.maxLength.trim();
    const maxLength = /^[0-9]+$/.test(attribute) ? Number(attribute) : 0;

    return {
        ...probe,
        maxLength: maxLength >= 1 && maxLength <= limit ? maxLength : typeMax,
    };
}

/**
 * 最大の文字数の説明文(数は stringMaxLengths から作る)。
 */
export function maxLengthHint(
    isMultiline: boolean,
    stringMaxLengths: StringMaxLengths,
): string {
    return isMultiline
        ? `空なら ${stringMaxLengths.multiline} 文字。${stringMaxLengths.multilineMax} 文字まで指定できます`
        : `空なら ${stringMaxLengths.singleLine} 文字`;
}

/**
 * 既定値の空の欄(真偽は偽。日付・日時は「指定しない」)。
 */
export function emptyDefaultFields(
    type: RecordFieldType | '',
): DefaultValueFields {
    return {
        defaultValue: type === 'boolean' ? false : '',
        defaultMode: 'none',
        relativeAmount: '',
        relativeUnit: 'D',
    };
}

/**
 * 編集の既定値の欄の初期値(相対の既定値は長さから数と単位を作る)。
 */
export function initialDefaultFields(
    field: RecordAdminField,
): DefaultValueFields {
    const empty = emptyDefaultFields(field.type);
    const relative =
        field.relativeDefault === null
            ? null
            : parseDuration(field.relativeDefault);

    if (relative !== null) {
        return {
            ...empty,
            defaultMode: 'relative',
            relativeAmount: relative.amount,
            relativeUnit: relative.unit,
        };
    }

    return field.defaultValue === null
        ? empty
        : {
              ...empty,
              defaultValue: currentDraft(field, field.defaultValue),
              defaultMode: 'fixed',
          };
}

/**
 * 既定値の送るキー(既定値を持てない型は送らない。日付・日時は持ち方で、指定しない = null・固定の値 = 欄の値・
 * 登録の時から = 保存形のオブジェクト)。
 */
export function defaultValuePayload(
    data: DefaultValueFormData,
): Record<string, unknown> {
    const probe = defaultValueProbe(data);

    if (probe === null) {
        return {};
    }

    if (!hasRelativeDefault(data.type) || data.defaultMode === 'fixed') {
        return { default_value: payloadValue(probe, data.defaultValue) };
    }

    return {
        default_value:
            data.defaultMode === 'relative'
                ? relativePayload(data.relativeAmount, data.relativeUnit)
                : null,
    };
}
