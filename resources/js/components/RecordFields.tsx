import { FormBlock, UnshownErrors } from '@/components/FormBlock';
import { RecordDetailsEditor } from '@/components/RecordDetailsEditor';
import { RecordFieldBadges } from '@/components/RecordFieldBadges';
import { RecordFieldInput } from '@/components/RecordFieldInput';
import { RecordHeadingGroups } from '@/components/RecordHeadingGroups';
import { Input } from '@/components/ui/input';
import type { RecordFieldDraft, RecordFormData } from '@/lib/recordForm';
import { drawnDetailsFields, isWideField, valueFields } from '@/lib/recordForm';
import {
    errorFieldCodes,
    fieldErrorOf,
    isRecordFieldKey,
    referenceItemErrors,
    shownRecordKeys,
} from '@/lib/recordFormErrors';
import { isRequiredNow } from '@/lib/recordRules';
import type {
    RecordFieldDefinition,
    RecordHeadingGroup,
    RecordModuleDefinition,
} from '@/types';

type Props = {
    module: RecordModuleDefinition;
    /** 見出しのまとまり(欄を見出しごとに畳める。レコードの共通機能/詳細の画面/見出し.md の 3 節) */
    headings: RecordHeadingGroup[];
    /** 送信の失敗ごとに増える合図(誤りの項目を含む見出しを開く。使い手が同じ合図で最初の誤りの欄へフォーカスを移す) */
    revealRequest: number;
    /** 書くレコードの id(他モジュール参照の候補から除く。登録・新規の申請・一時保存は null = 除かない) */
    recordId: number | null;
    /** 欄の値 */
    data: RecordFormData;
    /** 値の変更(今の値から次の値を作る関数を渡す。useForm の setData と同じ形) */
    onChange: (update: (data: RecordFormData) => RecordFormData) => void;
    /**
     * 明細の行の並びが変わった・複数の参照の欄のチップを外した。prefix で始まるキーの誤りを消す(使い手のフォームが誤りを持つので。
     * 添字がずれて誤りが別の行・相手に移らないように)
     */
    onRowsReordered: (prefix: string) => void;
    /** 検証のエラー(キー → 文言。values.<コード>・details.<コード>…) */
    errors: Record<string, string | undefined>;
    /** 変えられない欄(関連レコードの新規の入口の項目 = レコードの共通機能/関連レコード.md の 4.2 節)。text = 見せる値、hint = 下の 1 行 */
    fixedField?: FixedField;
};

/** 変えられない欄 */
type FixedField = { code: string; text: string; hint: string };

/**
 * 欄に結びつく値の誤り(欄に結びつかない誤りは入力欄の上に出す = 業務の画面.md 2.2「エラー」)。
 */
function fieldErrorsOf(errors: Props['errors']): Props['errors'] {
    return Object.fromEntries(
        Object.entries(errors).filter(([key]) => isRecordFieldKey(key)),
    );
}

type FieldRowProps = {
    module: RecordModuleDefinition;
    field: RecordFieldDefinition;
    recordId: number | null;
    /** 業務モジュールの欄の値 */
    values: Record<string, RecordFieldDraft>;
    onChange: (draft: RecordFieldDraft) => void;
    errors: Record<string, string | undefined>;
    /** prefix で始まるキーの誤りを消す */
    onClearErrors: (prefix: string) => void;
    /** 変えられない欄なら、その値と下の 1 行 */
    fixed: FixedField | undefined;
};

/**
 * 通常・参照の項目 1 つ(必須の印は入力中の値で評価する。誤りは欄のキーと、複数の参照の欄の添字のキー)。
 */
function FieldRow({
    module,
    field,
    recordId,
    values,
    onChange,
    errors,
    onClearErrors,
    fixed,
}: FieldRowProps) {
    const id = `record-field-${field.code}`;
    const key = `values.${field.code}`;
    const required = isRequiredNow(module, field, values);
    const error = fieldErrorOf(errors, key);

    return (
        <FormBlock
            id={id}
            label={field.name}
            labelAddon={<RecordFieldBadges field={field} required={required} />}
            error={error}
            hint={fixed?.hint}
            className={isWideField(field) ? 'md:col-span-2' : undefined}
        >
            {fixed === undefined ? (
                <RecordFieldInput
                    id={id}
                    field={field}
                    recordId={recordId}
                    draft={values[field.code] ?? null}
                    onChange={onChange}
                    isRequiredNow={required}
                    invalid={error !== undefined}
                    itemErrors={referenceItemErrors(errors, key, onClearErrors)}
                />
            ) : (
                <Input id={id} value={fixed.text} disabled readOnly />
            )}
        </FormBlock>
    );
}

/**
 * 見出しのまとまり 1 つの欄の格子(codes = そのまとまりの項目のコード)。
 */
function FieldsGrid({
    codes,
    module,
    recordId,
    data,
    onChange,
    errors,
    onClearErrors,
    fixedField,
}: Pick<Props, 'module' | 'recordId' | 'data' | 'errors' | 'fixedField'> & {
    codes: string[];
    onChange: (code: string, draft: RecordFieldDraft) => void;
    onClearErrors: (prefix: string) => void;
}) {
    const fields = valueFields(module).filter((field) =>
        codes.includes(field.code),
    );

    return (
        <div className="grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
            {fields.map((field) => (
                <FieldRow
                    key={field.code}
                    module={module}
                    field={field}
                    recordId={recordId}
                    values={data.values}
                    onChange={(draft) => onChange(field.code, draft)}
                    errors={errors}
                    onClearErrors={onClearErrors}
                    fixed={
                        fixedField?.code === field.code ? fixedField : undefined
                    }
                />
            ))}
        </div>
    );
}

/**
 * 明細の表(全部の見出しの後に全幅で、畳めない = 見出し.md の 1・3 節)。
 */
function DetailsEditors({
    module,
    recordId,
    data,
    onChange,
    onRowsReordered,
    errors,
}: Pick<
    Props,
    'module' | 'recordId' | 'data' | 'onChange' | 'onRowsReordered' | 'errors'
>) {
    return drawnDetailsFields(module).map((field) => (
        <RecordDetailsEditor
            key={field.code}
            field={field}
            recordId={recordId}
            rows={data.details[field.code] ?? []}
            onChange={(rows) =>
                onChange((current) => ({
                    ...current,
                    details: { ...current.details, [field.code]: rows },
                }))
            }
            onRowsReordered={onRowsReordered}
            isRequiredNow={isRequiredNow(module, field, data.values)}
            errors={errors}
        />
    ));
}

/**
 * 業務データの登録・編集の欄(業務の画面.md 2.2、見出しのまとまり = 見出し.md の 3 節)。値と誤りは props で受け、変更を上げる。
 * 送信は使い手(RecordForm と、ワークフローの申請の画面)が持つので、2 つの画面で同じ欄を使える。明細の表は全部の見出しの後に全幅で、畳めない。
 */
export function RecordFields({
    module,
    headings,
    revealRequest,
    recordId,
    data,
    onChange,
    onRowsReordered,
    errors,
    fixedField,
}: Props) {
    return (
        <>
            <UnshownErrors
                errors={fieldErrorsOf(errors)}
                shownKeys={shownRecordKeys(module, data)}
            />
            <RecordHeadingGroups
                groups={headings}
                idPrefix="record-fields"
                reveal={{
                    request: revealRequest,
                    codes: errorFieldCodes(errors),
                }}
            >
                {(group) => (
                    <FieldsGrid
                        codes={group.fieldCodes}
                        module={module}
                        recordId={recordId}
                        data={data}
                        errors={errors}
                        fixedField={fixedField}
                        onChange={(code, draft) =>
                            onChange((current) => ({
                                ...current,
                                values: { ...current.values, [code]: draft },
                            }))
                        }
                        onClearErrors={onRowsReordered}
                    />
                )}
            </RecordHeadingGroups>
            <DetailsEditors
                module={module}
                recordId={recordId}
                data={data}
                onChange={onChange}
                onRowsReordered={onRowsReordered}
                errors={errors}
            />
        </>
    );
}
