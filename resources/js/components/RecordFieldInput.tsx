import { CountedTextInput } from '@/components/CountedTextInput';
import { DateInput } from '@/components/DateInput';
import { invalidProps } from '@/components/FormBlock';
import { RecordChoicePicker } from '@/components/RecordChoicePicker';
import { RecordMultipleReferencePicker } from '@/components/RecordMultipleReferencePicker';
import { RecordReferencePicker } from '@/components/RecordReferencePicker';
import { RichTextInput } from '@/components/RichTextInput';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { RecordFieldDraft } from '@/lib/recordForm';
import { isReferenceField } from '@/lib/recordForm';
import type { ReferenceItemErrors } from '@/lib/recordFormErrors';
import type { RecordFieldDefinition } from '@/types';

type Props = {
    id: string;
    field: RecordFieldDefinition;
    /** 書くレコードの id(他モジュール参照の候補から除く。null = 除かない) */
    recordId: number | null;
    draft: RecordFieldDraft;
    onChange: (draft: RecordFieldDraft) => void;
    /** 今の入力で必須か(aria-required) */
    isRequiredNow: boolean;
    /** エラーがある(aria-invalid。誤りの文言は使い手が formErrorId(id) の要素で描く) */
    invalid: boolean;
    /** ラベルを持たない欄(明細の表のセル)の名前 */
    ariaLabel?: string;
    /** 複数選べる参照の添字の誤り(複数の参照の欄だけが使う) */
    itemErrors?: ReferenceItemErrors;
};

/**
 * 参照の項目の入力(1 つは参照の選択欄、複数選べる参照は複数の参照の欄 = docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2 の表)。
 */
function RecordReferenceInput({
    draft,
    invalid,
    itemErrors,
    describedBy,
    ...props
}: Omit<Props, 'isRequiredNow'> & { describedBy?: string }) {
    if (props.field.isMultiple) {
        return (
            <RecordMultipleReferencePicker
                {...props}
                value={Array.isArray(draft) ? draft : []}
                invalid={invalid}
                itemErrors={itemErrors}
                describedBy={describedBy}
            />
        );
    }

    return (
        <RecordReferencePicker
            {...props}
            value={
                draft !== null &&
                typeof draft === 'object' &&
                !Array.isArray(draft)
                    ? draft
                    : null
            }
            isRequired={props.field.isRequired}
            invalid={invalid}
            describedBy={describedBy}
        />
    );
}

/**
 * 文字の入力の入力モード(整数・小数の欄で数のキーボードを出す)。
 */
function inputModeOf(field: RecordFieldDefinition) {
    switch (field.type) {
        case 'integer':
            return 'numeric';
        case 'decimal':
            return 'decimal';
        default:
            return undefined;
    }
}

/**
 * 入力の下に文字数を出す項目か(上限のある文字列)。明細のセルが札の揃え方をこれで決める。
 */
export function hasCharacterCount(
    field: RecordFieldDefinition,
): field is RecordFieldDefinition & { maxLength: number } {
    return field.type === 'string' && field.maxLength !== null;
}

/** 入力に付ける共通の属性(id・名前・必須・誤り) */
type InputAttributes = {
    id: string;
    'aria-label'?: string;
    'aria-required'?: true;
    'aria-invalid'?: true;
    'aria-describedby'?: string;
};

/**
 * 上限のある 1 行の文字列の入力と、その下の文字数({@link CountedTextInput})。
 */
function CountedStringInput({
    max,
    text,
    common,
    invalid,
    onChange,
}: {
    max: number;
    text: string;
    common: InputAttributes;
    invalid: boolean;
    onChange: (draft: RecordFieldDraft) => void;
}) {
    // 数を出す欄は id と誤りの属性を部品が組み立てるので、残りだけを渡す
    const {
        id,
        'aria-invalid': _ariaInvalid,
        'aria-describedby': _ariaDescribedby,
        ...countedAttributes
    } = common;

    return (
        <div className="flex w-full flex-col gap-1">
            <CountedTextInput
                id={id}
                value={text}
                max={max}
                invalid={invalid}
                {...countedAttributes}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}

/**
 * 文字列の入力。複数行は札「編集」「プレビュー」つき({@link RichTextInput})、1 行は上限があれば下に文字数。
 */
function StringInput({
    field,
    text,
    common,
    onChange,
    isRequiredNow,
    invalid,
}: {
    field: RecordFieldDefinition;
    text: string;
    common: InputAttributes;
    onChange: (draft: RecordFieldDraft) => void;
    isRequiredNow: boolean;
    invalid: boolean;
}) {
    if (field.isMultiline) {
        return (
            <RichTextInput
                id={common.id}
                value={text}
                onChange={onChange}
                max={field.maxLength}
                invalid={invalid}
                isRequiredNow={isRequiredNow}
                ariaLabel={common['aria-label']}
            />
        );
    }

    return hasCharacterCount(field) ? (
        <CountedStringInput
            max={field.maxLength}
            text={text}
            common={common}
            invalid={invalid}
            onChange={onChange}
        />
    ) : (
        <Input
            {...common}
            value={text}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}

/**
 * フォームの 1 項目の入力(型ごとの部品 = docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2 の表。複数行の文字列は札つきの {@link RichTextInput})。
 */
export function RecordFieldInput({
    id,
    field,
    recordId,
    draft,
    onChange,
    isRequiredNow,
    invalid,
    ariaLabel,
    itemErrors,
}: Props) {
    // invalidProps は誤りの文言の有無だけを見る(文言は使い手が描く)
    const described = invalidProps(id, invalid ? '' : undefined);
    const common = {
        id,
        'aria-label': ariaLabel,
        'aria-required': isRequiredNow || undefined,
        ...described,
    };
    const text = typeof draft === 'string' ? draft : '';

    if (isReferenceField(field)) {
        return (
            <RecordReferenceInput
                id={id}
                field={field}
                recordId={recordId}
                draft={draft}
                onChange={onChange}
                invalid={invalid}
                itemErrors={itemErrors}
                describedBy={described['aria-describedby']}
                ariaLabel={ariaLabel}
            />
        );
    }

    switch (field.type) {
        case 'boolean':
            return (
                <Checkbox
                    {...common}
                    checked={draft === true}
                    onCheckedChange={(checked) => onChange(checked === true)}
                />
            );
        case 'choice':
            return (
                <RecordChoicePicker
                    id={id}
                    field={field}
                    value={text}
                    onChange={onChange}
                    isClearable={!field.isRequired}
                    isRequiredNow={isRequiredNow}
                    invalid={invalid}
                    describedBy={described['aria-describedby']}
                    ariaLabel={ariaLabel}
                />
            );
        case 'string':
            return (
                <StringInput
                    field={field}
                    text={text}
                    common={common}
                    onChange={onChange}
                    isRequiredNow={isRequiredNow}
                    invalid={invalid}
                />
            );
        case 'date':
        case 'datetime':
            return (
                <DateInput
                    {...common}
                    type={field.type === 'date' ? 'date' : 'datetime-local'}
                    value={text}
                    onChange={(event) => onChange(event.target.value)}
                    className="font-num tabular-nums"
                />
            );
        default:
            return (
                <Input
                    {...common}
                    type="text"
                    inputMode={inputModeOf(field)}
                    value={text}
                    onChange={(event) => onChange(event.target.value)}
                    className="font-num tabular-nums"
                />
            );
    }
}
