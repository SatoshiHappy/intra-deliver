import { useForm, usePage } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { CreateMeetingData } from '@/components/Meeting/createMeetingForm';
import {
    createMeetingPayload,
    initialCreateMeetingData,
    shownErrorKeys,
    topErrors,
} from '@/components/Meeting/createMeetingForm';
import {
    MeetingDialogFooter,
    MeetingFormErrors,
    MeetingNotices,
    MeetingSendingProgress,
    useFocusFirstError,
    useReportSending,
} from '@/components/Meeting/MeetingDialogParts';
import {
    acceptsOpenChange,
    numberProp,
} from '@/components/Meeting/meetingDialog';
import { MeetingFields } from '@/components/Meeting/MeetingFields';
import { MeetingImportInput } from '@/components/Meeting/MeetingImportInput';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { clearRowErrors } from '@/lib/formErrors';
import { store } from '@/routes/meeting';
import type { RecordAddonAction, RecordModuleDefinition } from '@/types';

/** 業務の画面の操作の口が開く部品の props(Records の部品の表の型と同じ形) */
type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    values: RecordAddonAction['props'];
};

/**
 * 欄の上の誤り・取り込み・注意書き・欄(ダイアログの中で縦に流す)。
 */
function CreateMeetingBody({
    module,
    textMaxBytes,
    form,
}: {
    module: RecordModuleDefinition;
    textMaxBytes: number;
    form: InertiaFormProps<CreateMeetingData>;
}) {
    const errors = form.errors as Record<string, string | undefined>;
    const { source } = form.data;
    const shown = shownErrorKeys(module, form.data);

    return (
        <div className="-mx-6 flex min-h-0 flex-col gap-5 overflow-y-auto px-6 py-1">
            <MeetingFormErrors errors={topErrors(module, errors, shown)} />
            <MeetingImportInput
                maxBytes={textMaxBytes}
                source={source}
                onSourceChange={(next) => form.setData('source', next)}
                text={form.data.text}
                onTextChange={(text) => form.setData('text', text)}
                onFileChange={(file) => form.setData('file', file)}
                errors={
                    source === 'file'
                        ? { file: errors.file }
                        : { text: errors.text }
                }
            />
            <MeetingNotices withConsent />
            <MeetingFields
                module={module}
                values={form.data.values}
                onChange={(code, draft) =>
                    form.setData((current) => ({
                        ...current,
                        values: { ...current.values, [code]: draft },
                    }))
                }
                errors={errors}
                onClearErrors={(prefix) => clearRowErrors(form, prefix)}
            />
        </div>
    );
}

/** フォームの props(上限は操作の props から、送っている間は親へ知らせる) */
type FormProps = {
    module: RecordModuleDefinition;
    textMaxBytes: number;
    onSendingChange: (sending: boolean) => void;
    onDone: () => void;
};

/**
 * ダイアログの中のフォーム(開くたびに作り直し、欄を既定値に戻す)。成功したら閉じる(入口が会議の一覧へ戻し、トーストを出す)。
 * 送っている間かを親へ知らせる(親が送る間の「閉じる」を捨てる)。
 */
function CreateMeetingForm(props: FormProps) {
    const { module, textMaxBytes, onSendingChange, onDone } = props;
    const [initial] = useState(() => initialCreateMeetingData(module));
    const form = useForm<CreateMeetingData>(initial);
    const { formRef, focusFirstError } = useFocusFirstError();

    useReportSending(form.processing, onSendingChange);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => createMeetingPayload(module, data));
        form.submit(store(), {
            preserveScroll: true,
            onSuccess: onDone,
            onError: focusFirstError,
        });
    };

    return (
        <form
            ref={formRef}
            noValidate
            onSubmit={submit}
            className="flex min-h-0 flex-col gap-4"
        >
            <DialogHeader>
                <DialogTitle>音声から作成</DialogTitle>
            </DialogHeader>
            <CreateMeetingBody
                module={module}
                textMaxBytes={textMaxBytes}
                form={form}
            />
            {form.processing && (
                <MeetingSendingProgress
                    percentage={form.progress?.percentage}
                />
            )}
            <MeetingDialogFooter
                processing={form.processing}
                submitLabel="作成"
            />
        </form>
    );
}

/**
 * 「音声から作成」のダイアログ(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.2。会議の一覧の操作の口が開く)。欄の定義は会議の一覧の画面が
 * 受けている業務モジュールの定義(Records/Index の props module)から引き、上限は操作の props(textMaxBytes)から引く。
 * 送っている間は閉じない。成功で閉じるのは守りを通さない(Inertia は成功の知らせを送っている間のまま呼ぶ)。
 */
export function CreateMeetingDialog({ open, onOpenChange, values }: Props) {
    const { module } = usePage<{ module: RecordModuleDefinition }>().props;
    const [sending, setSending] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (acceptsOpenChange(next, sending)) {
                    onOpenChange(next);
                }
            }}
        >
            <DialogContent
                aria-describedby={undefined}
                className="flex max-h-[90vh] flex-col sm:max-w-3xl"
            >
                <CreateMeetingForm
                    module={module}
                    textMaxBytes={numberProp(values, 'textMaxBytes')}
                    onSendingChange={setSending}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
