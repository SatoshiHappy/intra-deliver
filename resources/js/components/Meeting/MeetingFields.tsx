import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, invalidProps } from '@/components/FormBlock';
import {
    dialogFields,
    TITLE_FIELD_CODE,
} from '@/components/Meeting/createMeetingForm';
import { RecordFieldInput } from '@/components/RecordFieldInput';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import type { RecordFieldDraft } from '@/lib/recordForm';
import { isWideField } from '@/lib/recordForm';
import { fieldErrorOf, referenceItemErrors } from '@/lib/recordFormErrors';
import { isRequiredNow } from '@/lib/recordRules';
import type { RecordFieldDefinition, RecordModuleDefinition } from '@/types';

/** 欄の下の説明(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.2) */
const HINTS: Record<string, string> = {
    [TITLE_FIELD_CODE]: '入力した場合は、その件名を使用します。',
    project:
        '選択すると、そのプロジェクトの詳細の関連レコードに、この会議が表示されます。',
};

/** 件名の欄の中の薄い字(空なら AI が付ける) */
const TITLE_PLACEHOLDER = 'AI が設定します';

type Props = {
    module: RecordModuleDefinition;
    values: Record<string, RecordFieldDraft>;
    onChange: (code: string, draft: RecordFieldDraft) => void;
    errors: Record<string, string | undefined>;
    /** prefix で始まるキーの誤りを消す(複数の参照の欄のチップを外したとき) */
    onClearErrors: (prefix: string) => void;
};

/**
 * 件名の入力(入れ物の文字列の欄と同じ部品に、薄い字を添える)。
 */
function TitleInput({
    id,
    field,
    value,
    invalid,
    onChange,
}: {
    id: string;
    field: RecordFieldDefinition;
    value: string;
    invalid: boolean;
    onChange: (text: string) => void;
}) {
    if (field.maxLength === null) {
        return (
            <Input
                id={id}
                value={value}
                placeholder={TITLE_PLACEHOLDER}
                {...invalidProps(id, invalid ? '' : undefined)}
                onChange={(event) => onChange(event.target.value)}
            />
        );
    }

    return (
        <div className="flex w-full flex-col gap-1">
            <CountedTextInput
                id={id}
                value={value}
                max={field.maxLength}
                invalid={invalid}
                placeholder={TITLE_PLACEHOLDER}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}

/**
 * 項目の入力(件名は薄い字つきの文字列の入力、ほかは入れ物の型ごとの部品)。
 */
function FieldInput({
    id,
    field,
    required,
    error,
    ...props
}: Props & {
    id: string;
    field: RecordFieldDefinition;
    required: boolean;
    error: string | undefined;
}) {
    const draft = props.values[field.code] ?? null;
    const change = (next: RecordFieldDraft) => props.onChange(field.code, next);

    return field.code === TITLE_FIELD_CODE ? (
        <TitleInput
            id={id}
            field={field}
            value={typeof draft === 'string' ? draft : ''}
            invalid={error !== undefined}
            onChange={change}
        />
    ) : (
        <RecordFieldInput
            id={id}
            field={field}
            recordId={null}
            draft={draft}
            onChange={change}
            isRequiredNow={required}
            invalid={error !== undefined}
            itemErrors={referenceItemErrors(
                props.errors,
                `values.${field.code}`,
                props.onClearErrors,
            )}
        />
    );
}

/**
 * 項目 1 つ(必須の印は入力中の値で評価する。件名はダイアログでは任意)。
 */
function FieldRow({
    field,
    ...props
}: Props & { field: RecordFieldDefinition }) {
    const id = `meeting-field-${field.code}`;
    const required =
        field.code !== TITLE_FIELD_CODE &&
        isRequiredNow(props.module, field, props.values);
    const error = fieldErrorOf(props.errors, `values.${field.code}`);

    return (
        <FormBlock
            id={id}
            label={field.name}
            labelAddon={
                required ? <Badge variant="secondary">必須</Badge> : undefined
            }
            hint={HINTS[field.code]}
            error={error}
            className={isWideField(field) ? 'md:col-span-2' : undefined}
        >
            <FieldInput
                id={id}
                field={field}
                required={required}
                error={error}
                {...props}
            />
        </FormBlock>
    );
}

/**
 * 「音声から作成」の欄(会議の業務モジュールの定義から引き、入れ物のフォームの部品で描く)。削除予定日は出さない(既定が入る)。
 */
export function MeetingFields(props: Props) {
    return (
        <div className="grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
            {dialogFields(props.module).map((field) => (
                <FieldRow key={field.code} field={field} {...props} />
            ))}
        </div>
    );
}
