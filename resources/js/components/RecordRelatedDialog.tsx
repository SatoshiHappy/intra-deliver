import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { UnshownErrors } from '@/components/FormBlock';
import { RecordFields } from '@/components/RecordFields';
import { RecordRowAccessSection } from '@/components/RecordRowAccessSection';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useFocusFirstInvalid } from '@/hooks/use-focus-first-invalid';
import { clearRowErrors } from '@/lib/formErrors';
import type { RecordFormData } from '@/lib/recordForm';
import { toPayload } from '@/lib/recordPayload';
import {
    isRowAccessKey,
    rowAccessDraft,
    rowAccessPayload,
} from '@/lib/recordRowAccess';
import { isRecordFieldKey } from '@/lib/recordFormErrors';
import type { RelatedDialogStatus } from '@/lib/recordRelated';
import { addedMessage, relatedFormData } from '@/lib/recordRelated';
import { referenceLabel } from '@/lib/recordValues';
import type { RouteDefinition } from '@/wayfinder';
import type { RecordRelatedForm } from '@/types';

/** フォームを読み込めなかったときの 1 行 */
const LOAD_FAILED =
    '登録の画面を読み込めませんでした。閉じて、もう一度お試しください。';

/** 入口の項目の下の 1 行(関連レコード.md の 4.2 節) */
const FIXED_HINT = '関連レコードとして登録するため、変更できません。';

type FormProps = {
    form: RecordRelatedForm;
    /** このレコードの id(送る related.record) */
    recordId: number;
    /** 送る先(参照元の業務モジュールの records.data.store。呼び手のページがルートから作る) */
    storeAction: (moduleCode: string) => RouteDefinition<'post'>;
    onClose: () => void;
};

/**
 * 送信(送る形 = 登録の画面と同じ値(参照制限を含む)に related を足す)。「登録して続けて作成」は閉じずに入力を初期値へ戻し、登録した数を数える。
 * 誤り(422)はダイアログを開いたまま欄の下に出し、最初の誤りの欄へフォーカスを移す合図を増やす(4.2 節)。
 */
function useRelatedSubmit({ form, recordId, storeAction, onClose }: FormProps) {
    const data = useForm<RecordFormData>(relatedFormData(form));
    const [rowAccess, setRowAccess] = useState(() =>
        rowAccessDraft(form.rowAccess),
    );
    const [added, setAdded] = useState(0);
    const [focusRequest, setFocusRequest] = useState(0);

    const send = (continues: boolean) => {
        data.transform((current) => ({
            ...toPayload(form.module, current),
            ...rowAccessPayload(rowAccess),
            related: { field: form.field, record: recordId },
        }));
        data.submit(storeAction(form.module.code), {
            preserveScroll: true,
            onSuccess: () => {
                if (!continues) {
                    onClose();

                    return;
                }

                setAdded((count) => count + 1);
                data.clearErrors();
                data.setData(relatedFormData(form));
                setRowAccess(rowAccessDraft(form.rowAccess));
            },
            onError: () => setFocusRequest((count) => count + 1),
        });
    };

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        send(false);
    };

    return {
        data,
        rowAccess,
        setRowAccess,
        added,
        focusRequest,
        send,
        onSubmit,
    };
}

/** ダイアログのボタンの列(主ボタン「登録」が右端) */
function RelatedButtons({
    processing,
    onCancel,
    onContinue,
}: {
    processing: boolean;
    onCancel: () => void;
    onContinue: () => void;
}) {
    return (
        <DialogFooter>
            <Button type="button" variant="outline" onClick={onCancel}>
                キャンセル
            </Button>
            <Button
                type="button"
                variant="outline"
                disabled={processing}
                onClick={onContinue}
            >
                登録して続けて作成
            </Button>
            <Button type="submit" disabled={processing}>
                登録
            </Button>
        </DialogFooter>
    );
}

/**
 * ダイアログの中のフォーム(開くたびに作り直す)。入口の項目は押せない形で、このレコードの表示名と下の 1 行を出す。
 */
function RelatedForm(props: FormProps) {
    const { form } = props;
    const related = useRelatedSubmit(props);
    const { data, added, focusRequest } = related;
    const errors = data.errors as Record<string, string | undefined>;
    const clearErrors = (prefix: string) => clearRowErrors(data, prefix);
    const formRef = useRef<HTMLFormElement>(null);
    useFocusFirstInvalid(formRef, focusRequest);

    return (
        <form
            ref={formRef}
            noValidate
            onSubmit={related.onSubmit}
            aria-label={`${form.module.name}の入力`}
            className="flex min-h-0 flex-col gap-4"
        >
            {added > 0 && (
                <p role="status" className="text-sm font-medium text-primary">
                    {addedMessage(added)}
                </p>
            )}
            <UnshownErrors errors={unshownErrors(errors)} shownKeys={[]} />
            <div className="flex min-h-0 flex-col gap-5 overflow-y-auto pr-1">
                <RecordFields
                    module={form.module}
                    headings={form.headings}
                    revealRequest={focusRequest}
                    recordId={null}
                    data={data.data}
                    onChange={(update) => data.setData(update)}
                    onRowsReordered={clearErrors}
                    errors={errors}
                    fixedField={fixedFieldOf(form)}
                />
                <RecordRowAccessSection
                    draft={related.rowAccess}
                    onChange={related.setRowAccess}
                    errors={errors}
                    onClearErrors={clearErrors}
                />
            </div>
            <RelatedButtons
                processing={data.processing}
                onCancel={props.onClose}
                onContinue={() => related.send(true)}
            />
        </form>
    );
}

/** 入口の項目(押せない形で、このレコードの表示名と下の 1 行を出す) */
function fixedFieldOf(form: RecordRelatedForm) {
    const field = form.module.fields.find((item) => item.code === form.field);

    return {
        code: form.field,
        // 項目が見つからなければ、今の定義の他モジュール参照と同じ表示(名前か #<id>)
        text:
            field === undefined
                ? (form.value.label ?? `#${form.value.id}`)
                : referenceLabel(field, form.value),
        hint: FIXED_HINT,
    };
}

/** フォームが届くまでの 1 行(届かないまま要求が終わったら、読み込めなかったことと閉じて試し直すこと) */
function LoadingLine({ failed }: { failed: boolean }) {
    return failed ? (
        <p role="alert" className="text-sm text-destructive">
            {LOAD_FAILED}
        </p>
    ) : (
        <p className="text-sm text-muted-foreground">読み込んでいます</p>
    );
}

/** 業務の欄と参照制限に結びつかない誤り(related など。フォームの上に出す) */
function unshownErrors(
    errors: Record<string, string | undefined>,
): Record<string, string | undefined> {
    return Object.fromEntries(
        Object.entries(errors).filter(
            ([key]) => !isRecordFieldKey(key) && !isRowAccessKey(key),
        ),
    );
}

/**
 * 関連レコードの「新規」のダイアログ(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 4 節): 題
 * 「<参照元の業務モジュール>の登録」、先頭に「<この業務モジュール>「<このレコードの表示>」の関連レコードとして登録します。」。
 * フォームは開いた時の部分の再読み込みで引く(届くまで「読み込んでいます」)。ownerText = 「<この業務モジュール>「<このレコードの表示>」」。
 */
export function RecordRelatedDialog({
    open,
    form,
    status,
    ownerText,
    ...props
}: Omit<FormProps, 'form'> & {
    open: boolean;
    form: RecordRelatedForm | null;
    status: RelatedDialogStatus;
    ownerText: string;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && props.onClose()}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {form === null
                            ? '関連レコードの登録'
                            : `${form.module.name}の登録`}
                    </DialogTitle>
                    <DialogDescription>
                        {ownerText}の関連レコードとして登録します。
                    </DialogDescription>
                </DialogHeader>
                {form === null ? (
                    <LoadingLine failed={status === 'failed'} />
                ) : (
                    <RelatedForm key={form.entry} form={form} {...props} />
                )}
            </DialogContent>
        </Dialog>
    );
}
