import { FormBlock, UnshownErrors } from '@/components/FormBlock';
import { RecordDetailsEditor } from '@/components/RecordDetailsEditor';
import { RecordFieldInput } from '@/components/RecordFieldInput';
import { Badge } from '@/components/ui/badge';
import type { RecordFieldDraft, RecordFormData } from '@/lib/recordForm';
import { drawnDetailsFields, isWideField, valueFields } from '@/lib/recordForm';
import {
    fieldErrorOf,
    isRecordFieldKey,
    referenceItemErrors,
    shownRecordKeys,
} from '@/lib/recordFormErrors';
import { isRequiredNow } from '@/lib/recordRules';
import type { RecordFieldDefinition, RecordModuleDefinition } from '@/types';

type Props = {
    module: RecordModuleDefinition;
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
};

/** エラーのある欄とフォームの上のエラー(送った後に最初のものへフォーカスを移す。業務データの入れ物の登録・編集とワークフローの申請の画面で同じ) */
export const INVALID_SELECTOR = '[aria-invalid="true"], [role="alert"]';

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
}: {
    module: RecordModuleDefinition;
    field: RecordFieldDefinition;
    recordId: number | null;
    /** 業務モジュールの欄の値 */
    values: Record<string, RecordFieldDraft>;
    onChange: (draft: RecordFieldDraft) => void;
    errors: Record<string, string | undefined>;
    /** prefix で始まるキーの誤りを消す */
    onClearErrors: (prefix: string) => void;
}) {
    const id = `record-field-${field.code}`;
    const key = `values.${field.code}`;
    const required = isRequiredNow(module, field, values);
    const error = fieldErrorOf(errors, key);

    return (
        <FormBlock
            id={id}
            label={field.name}
            labelAddon={
                required ? <Badge variant="secondary">必須</Badge> : undefined
            }
            error={error}
            className={isWideField(field) ? 'md:col-span-2' : undefined}
        >
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
        </FormBlock>
    );
}

/**
 * 業務データの登録・編集の欄(業務の画面.md 2.2)。値と誤りは props で受け、変更を上げる。送信は使い手(RecordForm と、
 * ワークフローの申請の画面)が持つので、2 つの画面で同じ欄を使える。
 */
export function RecordFields({
    module,
    recordId,
    data,
    onChange,
    onRowsReordered,
    errors,
}: Props) {
    const plainFields = valueFields(module);
    const detailsFields = drawnDetailsFields(module);

    // 欄に結びつかない値の誤りは入力欄の上に出す(業務の画面.md 2.2「エラー」)
    const fieldErrors = Object.fromEntries(
        Object.entries(errors).filter(([key]) => isRecordFieldKey(key)),
    );

    return (
        <>
            <UnshownErrors
                errors={fieldErrors}
                shownKeys={shownRecordKeys(module, data)}
            />
            <div className="grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
                {plainFields.map((field) => (
                    <FieldRow
                        key={field.code}
                        module={module}
                        field={field}
                        recordId={recordId}
                        values={data.values}
                        onChange={(draft) =>
                            onChange((current) => ({
                                ...current,
                                values: {
                                    ...current.values,
                                    [field.code]: draft,
                                },
                            }))
                        }
                        errors={errors}
                        onClearErrors={onRowsReordered}
                    />
                ))}
            </div>
            {detailsFields.map((field) => (
                <RecordDetailsEditor
                    key={field.code}
                    field={field}
                    recordId={recordId}
                    rows={data.details[field.code] ?? []}
                    onChange={(rows) =>
                        onChange((current) => ({
                            ...current,
                            details: {
                                ...current.details,
                                [field.code]: rows,
                            },
                        }))
                    }
                    onRowsReordered={onRowsReordered}
                    isRequiredNow={isRequiredNow(module, field, data.values)}
                    errors={errors}
                />
            ))}
        </>
    );
}
