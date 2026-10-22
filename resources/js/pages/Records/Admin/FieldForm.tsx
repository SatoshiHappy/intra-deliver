import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, invalidProps } from '@/components/FormBlock';
import {
    FormActions,
    FormSurface,
    TWO_SURFACES_GRID,
} from '@/components/FormLayout';
import { RecordFieldAttributeFlags } from '@/components/Records/RecordFieldAttributeFlags';
import { RecordFieldDefaultValue } from '@/components/Records/RecordFieldDefaultValue';
import { RecordOptionsEditor } from '@/components/Records/RecordOptionsEditor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCodeCheck } from '@/hooks/Records/use-code-check';
import { useFocusFirstInvalid } from '@/hooks/use-focus-first-invalid';
import { clearRowErrors, clearServerErrors } from '@/lib/formErrors';
import {
    defaultValueField,
    maxLengthHint,
} from '@/lib/Records/fieldDefaultValue';
import { codeError } from '@/lib/Records/recordDefinitionCode';
import type { FieldTypeChoice } from '@/lib/Records/recordDefinitions';
import {
    FIELD_TYPE_CHOICES,
    fieldTypeLabel,
    typeChoiceOf,
} from '@/lib/Records/recordDefinitions';
import type { FieldFormData } from '@/lib/Records/recordFieldForm';
import {
    initialFieldData,
    toFieldPayload,
    withTypeChoice,
} from '@/lib/Records/recordFieldForm';
import { create, edit, store, update } from '@/routes/records/admin/fields';
import { index, show } from '@/routes/records/admin/modules';
import type {
    DefinitionTextMaxLengths,
    RecordAdminField,
    RecordModuleSummary,
    StringMaxLengths,
} from '@/types';

type Props = {
    module: RecordModuleSummary & { isDetails: boolean };
    /** 編集する項目(null = 追加) */
    field: RecordAdminField | null;
    /** 他モジュール参照の参照先の候補(親の無い業務モジュール) */
    referableModules: RecordModuleSummary[];
    /** コード・名前の文字数の上限(選択肢のコード・名称にも使う) */
    textMaxLengths: DefinitionTextMaxLengths;
    /** 文字列の型の上限と複数行に指定できる上限(最大の文字数の説明文と、既定値の欄の上限) */
    stringMaxLengths: StringMaxLengths;
};

/**
 * 見出し(追加 =「項目の追加」、編集 =「項目「<名前>」の編集」)。
 */
function headingOf(field: RecordAdminField | null) {
    return field === null ? '項目の追加' : `項目「${field.name}」の編集`;
}

type SetField = <K extends keyof FieldFormData>(
    key: K,
    value: FieldFormData[K],
) => void;

/**
 * 型の一覧(追加のときだけ。並びと出し分け = 画面/管理の画面.md 3.4)。選んだ選択肢を欄の値にする(送る形は type + is_multiline・is_multiple)。
 */
function TypeSelect({
    data,
    isDetailsModule,
    error,
    onChange,
}: {
    data: FieldFormData;
    isDetailsModule: boolean;
    error: string | undefined;
    onChange: (choice: FieldTypeChoice) => void;
}) {
    const choices = FIELD_TYPE_CHOICES.filter(
        (choice) => !(isDetailsModule && choice.type === 'details'),
    );
    const selected = typeChoiceOf(data, choices);

    return (
        <FormBlock id="field-type" label="型" isRequired error={error}>
            <Select
                value={selected?.value ?? ''}
                onValueChange={(value) => {
                    const choice = choices.find((item) => item.value === value);

                    if (choice !== undefined) {
                        onChange(choice);
                    }
                }}
            >
                <SelectTrigger
                    id="field-type"
                    className="w-full"
                    {...invalidProps('field-type', error)}
                >
                    <SelectValue placeholder="選択してください" />
                </SelectTrigger>
                <SelectContent>
                    {choices.map((choice) => (
                        <SelectItem key={choice.value} value={choice.value}>
                            {fieldTypeLabel(choice)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </FormBlock>
    );
}

/**
 * 管理の項目の追加・編集(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.4)。
 */
export default function FieldForm({
    module,
    field,
    referableModules,
    textMaxLengths,
    stringMaxLengths,
}: Props) {
    const isNew = field === null;
    const heading = headingOf(field);
    // 初期値は 1 回だけ作る(useForm は関数を描画ごとに呼ぶので、useState で持つ)
    const [initial] = useState(() => initialFieldData(field));
    const form = useForm<FieldFormData>(initial);
    const errors = form.errors as Record<string, string | undefined>;
    const data = form.data;
    const formRef = useRef<HTMLFormElement>(null);
    const [focusRequest, setFocusRequest] = useState(0);
    // コードの入力時の検査(lib/Records/recordDefinitionCode)は欄を離れたときから。サーバーの誤りが先に出て、欄を変えるとその欄のサーバーの誤りは消える
    const codeCheck = useCodeCheck({
        value: data.code,
        serverError: errors.code,
        clearServerError: () => clearServerErrors(form, ['code']),
        check: codeError,
    });
    const childCodeCheck = useCodeCheck({
        value: data.childCode,
        serverError: errors['child_module.code'],
        clearServerError: () => clearServerErrors(form, ['child_module.code']),
        check: codeError,
    });
    const probe = defaultValueField(data, stringMaxLengths);
    const set: SetField = (key, value) =>
        form.setData((current) => ({ ...current, [key]: value }));

    useFocusFirstInvalid(formRef, focusRequest);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((current) => toFieldPayload(current, isNew));
        form.submit(
            isNew
                ? store(module.code)
                : update({ module: module.code, field: field.code }),
            { onError: () => setFocusRequest((count) => count + 1) },
        );
    };
    const invalid = (key: string) =>
        invalidProps(`field-${key.replace('.', '-')}`, errors[key]);

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">{heading}</h1>
                <form
                    ref={formRef}
                    noValidate
                    onSubmit={submit}
                    aria-label="項目の入力"
                    className="flex flex-col gap-6"
                >
                    {/* 並び順の入力は無いので、追加の並び順の誤り(上限)はフォームの面の先頭に出す(画面/管理の画面.md 3.4) */}
                    {errors.sort_key !== undefined && (
                        <p
                            role="alert"
                            tabIndex={-1}
                            className="text-sm text-destructive"
                        >
                            {errors.sort_key}
                        </p>
                    )}
                    <div className={TWO_SURFACES_GRID}>
                        <FormSurface
                            aria-labelledby="field-basic"
                            inner="max-w-2xl"
                        >
                            <h2
                                id="field-basic"
                                className="text-[15px] font-semibold"
                            >
                                基本
                            </h2>
                            {isNew ? (
                                <FormBlock
                                    id="field-code"
                                    label="コード"
                                    isRequired
                                    hint="英小文字で始まる英小文字・数字・_ だけ。追加後は変更できません"
                                    error={codeCheck.shownError}
                                >
                                    <CountedTextInput
                                        id="field-code"
                                        value={data.code}
                                        max={textMaxLengths.code}
                                        invalid={
                                            codeCheck.shownError !== undefined
                                        }
                                        onChange={(event) => {
                                            set('code', event.target.value);
                                            codeCheck.onChange();
                                        }}
                                        onBlur={codeCheck.onBlur}
                                    />
                                </FormBlock>
                            ) : (
                                <p className="text-sm">
                                    <span className="text-muted-foreground">
                                        コード:{' '}
                                    </span>
                                    {field.code}
                                    <span className="ml-4 text-muted-foreground">
                                        型:{' '}
                                    </span>
                                    {fieldTypeLabel(field)}
                                </p>
                            )}
                            <FormBlock
                                id="field-name"
                                label="名前"
                                isRequired
                                error={errors.name}
                            >
                                <CountedTextInput
                                    id="field-name"
                                    value={data.name}
                                    max={textMaxLengths.name}
                                    invalid={errors.name !== undefined}
                                    onChange={(event) =>
                                        set('name', event.target.value)
                                    }
                                />
                            </FormBlock>
                            {isNew && (
                                <TypeSelect
                                    data={data}
                                    isDetailsModule={module.isDetails}
                                    error={
                                        errors.type ??
                                        errors.is_multiline ??
                                        errors.is_multiple
                                    }
                                    onChange={(choice) =>
                                        form.setData((current) =>
                                            withTypeChoice(current, choice),
                                        )
                                    }
                                />
                            )}
                            {isNew && data.type === 'record' && (
                                <FormBlock
                                    id="field-referenced_module"
                                    label="参照先"
                                    isRequired
                                    error={errors.referenced_module}
                                >
                                    <Select
                                        value={data.referencedModule}
                                        onValueChange={(code) =>
                                            set('referencedModule', code)
                                        }
                                    >
                                        <SelectTrigger
                                            id="field-referenced_module"
                                            className="w-full"
                                            {...invalid('referenced_module')}
                                        >
                                            <SelectValue placeholder="選択してください" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {referableModules.map(
                                                (referable) => (
                                                    <SelectItem
                                                        key={referable.code}
                                                        value={referable.code}
                                                    >
                                                        {referable.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </FormBlock>
                            )}
                            {!isNew && field.referencedModule !== null && (
                                <p className="text-sm">
                                    <span className="text-muted-foreground">
                                        参照先:{' '}
                                    </span>
                                    {field.referencedModule.name}
                                </p>
                            )}
                            {isNew && data.type === 'details' && (
                                <div className="grid grid-cols-2 gap-4">
                                    <FormBlock
                                        id="field-child_module-code"
                                        label="明細のモジュールのコード"
                                        isRequired
                                        error={childCodeCheck.shownError}
                                    >
                                        <CountedTextInput
                                            id="field-child_module-code"
                                            value={data.childCode}
                                            max={textMaxLengths.code}
                                            invalid={
                                                childCodeCheck.shownError !==
                                                undefined
                                            }
                                            onChange={(event) => {
                                                set(
                                                    'childCode',
                                                    event.target.value,
                                                );
                                                childCodeCheck.onChange();
                                            }}
                                            onBlur={childCodeCheck.onBlur}
                                        />
                                    </FormBlock>
                                    <FormBlock
                                        id="field-child_module-name"
                                        label="明細のモジュールの名前"
                                        isRequired
                                        error={errors['child_module.name']}
                                    >
                                        <CountedTextInput
                                            id="field-child_module-name"
                                            value={data.childName}
                                            max={textMaxLengths.name}
                                            invalid={
                                                errors['child_module.name'] !==
                                                undefined
                                            }
                                            onChange={(event) =>
                                                set(
                                                    'childName',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </FormBlock>
                                    {errors.child_module !== undefined && (
                                        <p className="col-span-2 text-sm text-destructive">
                                            {errors.child_module}
                                        </p>
                                    )}
                                </div>
                            )}
                            {data.type === 'string' && (
                                <FormBlock
                                    id="field-max_length"
                                    label="最大の文字数"
                                    hint={maxLengthHint(
                                        data.isMultiline,
                                        stringMaxLengths,
                                    )}
                                    error={errors.max_length}
                                >
                                    <Input
                                        id="field-max_length"
                                        inputMode="numeric"
                                        value={data.maxLength}
                                        onChange={(event) =>
                                            set('maxLength', event.target.value)
                                        }
                                        className="max-w-40 font-num tabular-nums"
                                        {...invalid('max_length')}
                                    />
                                </FormBlock>
                            )}
                            {data.type === 'choice' && (
                                <RecordOptionsEditor
                                    options={data.options}
                                    onChange={(options) =>
                                        set('options', options)
                                    }
                                    errors={errors}
                                    textMaxLengths={textMaxLengths}
                                    onCodeEdited={(index) =>
                                        clearServerErrors(form, [
                                            `options.${index}.code`,
                                        ])
                                    }
                                    onRowsReordered={() =>
                                        clearRowErrors(form, 'options.')
                                    }
                                />
                            )}
                            <RecordFieldDefaultValue
                                data={data}
                                field={probe}
                                error={errors.default_value}
                                onChange={(patch) =>
                                    form.setData((current) => ({
                                        ...current,
                                        ...patch,
                                    }))
                                }
                            />
                        </FormSurface>
                        <FormSurface
                            aria-labelledby="field-attributes"
                            inner="max-w-2xl"
                            gap={4}
                        >
                            <h2
                                id="field-attributes"
                                className="text-[15px] font-semibold"
                            >
                                属性
                            </h2>
                            {data.type === '' ? (
                                <p className="text-sm text-muted-foreground">
                                    型の選択後に表示されます
                                </p>
                            ) : (
                                <RecordFieldAttributeFlags
                                    data={data}
                                    errors={errors}
                                    onChange={set}
                                />
                            )}
                        </FormSurface>
                    </div>
                    <FormActions>
                        <Button variant="outline" asChild>
                            <Link href={show(module.code)}>キャンセル</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {isNew ? '追加' : '更新'}
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}

FieldForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: '業務モジュール', href: index() },
        { title: props.module.name, href: show(props.module.code) },
        {
            title: headingOf(props.field),
            href:
                props.field === null
                    ? create(props.module.code)
                    : edit({
                          module: props.module.code,
                          field: props.field.code,
                      }),
        },
    ],
});
