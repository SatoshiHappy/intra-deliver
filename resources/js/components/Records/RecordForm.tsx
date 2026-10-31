import { Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { UnshownErrors } from '@/components/FormBlock';
import { FormActions, FormSurface } from '@/components/FormLayout';
import { RecordFields } from '@/components/RecordFields';
import { RecordRowAccessSection } from '@/components/RecordRowAccessSection';
import { Button } from '@/components/ui/button';
import { useFocusFirstInvalid } from '@/hooks/use-focus-first-invalid';
import type { RecordFormData } from '@/lib/recordForm';
import { toPayload } from '@/lib/recordPayload';
import { isRecordFieldKey } from '@/lib/recordFormErrors';
import {
    isRowAccessKey,
    rowAccessDraft,
    rowAccessPayload,
} from '@/lib/recordRowAccess';
import { clearRowErrors } from '@/lib/formErrors';
import type { RouteDefinition } from '@/wayfinder';
import type {
    RecordHeadingGroup,
    RecordModuleDefinition,
    RowAccessInput,
} from '@/types';

type Props = {
    module: RecordModuleDefinition;
    /** 見出しのまとまり(欄を見出しごとに畳める) */
    headings: RecordHeadingGroup[];
    /** 書くレコードの id(編集 = そのレコード、登録 = null) */
    recordId: number | null;
    /** 欄の初期値(登録 = 既定値、編集 = 今の値) */
    initialData: RecordFormData;
    /** 参照制限の初期値(登録 = 業務モジュールの既定と空、編集 = 今の値、複製 = 元の値) */
    initialRowAccess: RowAccessInput;
    /** 開いたときの版(編集だけ。登録は無し = 画面/業務の画面.md 2.2) */
    version?: string;
    /** 送る先(登録 = records.data.store の post、更新 = records.data.update の put) */
    action: RouteDefinition<'post' | 'put'>;
    submitLabel: string;
    /** 送るボタンを押せなくする(編集で、レコードに持ち主があるとき = 画面/業務の画面.md 2.2) */
    submitDisabled?: boolean;
    /** 「キャンセル」の移り先(登録 = 一覧、編集 = 詳細。null = 出さない = 登録だけを許され、一覧を開けない利用者) */
    cancelHref: RouteDefinition<'get'> | null;
};

/**
 * フォームの値・参照制限の欄の値・送信(送る形 = toPayload の全項目に参照制限の 2 つのキーと、編集は開いたときの版を足す)。
 */
function useRecordSubmit({
    module,
    initialData,
    initialRowAccess,
    version,
    action,
}: Pick<
    Props,
    'module' | 'initialData' | 'initialRowAccess' | 'version' | 'action'
>) {
    // 初期値は 1 回だけ作る(useForm は関数を描画ごとに呼ぶので、useState で持つ)
    const [initial] = useState(initialData);
    const [rowAccess, setRowAccess] = useState(() =>
        rowAccessDraft(initialRowAccess),
    );
    // 版も欄の初期値と同じ時点の写しを持つ。422 で戻ると props の版は新しくなるが、残った古い値を新しい版で送ると上書きが通るため
    const [openedVersion] = useState(version);
    const form = useForm<RecordFormData>(initial);
    const formRef = useRef<HTMLFormElement>(null);
    const [focusRequest, setFocusRequest] = useState(0);

    useFocusFirstInvalid(formRef, focusRequest);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...toPayload(module, data),
            ...rowAccessPayload(rowAccess),
            ...(openedVersion === undefined ? {} : { version: openedVersion }),
        }));
        form.submit(action, {
            onError: () => setFocusRequest((count) => count + 1),
        });
    };

    return { form, rowAccess, setRowAccess, formRef, focusRequest, submit };
}

/**
 * 業務の登録・編集のフォーム(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2): 欄は {@link RecordFields}(ワークフローの申請の画面と共通)
 * と、その後の区画「参照制限」。失敗の出し方 = 項目と規則/規則と業務処理/失敗と定義の変更.md 10 節。
 */
export function RecordForm(props: Props) {
    const { module, headings, recordId, submitLabel, cancelHref } = props;
    const { form, rowAccess, setRowAccess, formRef, focusRequest, submit } =
        useRecordSubmit(props);
    const errors = form.errors as Record<string, string | undefined>;
    // 業務の欄と参照制限の誤り以外(record・version ほか)はフォームの上に出す
    const formErrors = Object.fromEntries(
        Object.entries(errors).filter(
            ([key]) => !isRecordFieldKey(key) && !isRowAccessKey(key),
        ),
    );
    const clearErrors = (prefix: string) => clearRowErrors(form, prefix);

    return (
        <form
            ref={formRef}
            noValidate
            onSubmit={submit}
            aria-label={`${module.name}の入力`}
            className="flex flex-col gap-6"
        >
            <UnshownErrors errors={formErrors} shownKeys={[]} />
            <FormSurface inner="max-w-5xl">
                <RecordFields
                    module={module}
                    headings={headings}
                    revealRequest={focusRequest}
                    recordId={recordId}
                    data={form.data}
                    onChange={(update) => form.setData(update)}
                    onRowsReordered={clearErrors}
                    errors={errors}
                />
            </FormSurface>
            <FormSurface inner="max-w-5xl">
                <RecordRowAccessSection
                    draft={rowAccess}
                    onChange={setRowAccess}
                    errors={errors}
                    onClearErrors={clearErrors}
                />
            </FormSurface>
            <FormActions>
                {cancelHref !== null && (
                    <Button variant="outline" asChild>
                        <Link href={cancelHref}>キャンセル</Link>
                    </Button>
                )}
                <Button
                    type="submit"
                    disabled={form.processing || props.submitDisabled === true}
                >
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
