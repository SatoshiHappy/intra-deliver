import { useForm, usePage } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { UnshownErrors } from '@/components/FormBlock';
import type { CreateMeetingData } from '@/components/Meeting/createMeetingForm';
import {
    blockedFileError,
    createMeetingPayload,
    IMPORT_FILE_ID,
    initialCreateMeetingData,
    namedErrors,
    shownErrorKeys,
} from '@/components/Meeting/createMeetingForm';
import {
    MeetingAudioBlockedNotice,
    MeetingDialogFooter,
    MeetingNotices,
    useFocusFirstError,
    useReportSending,
} from '@/components/Meeting/MeetingDialogParts';
import {
    acceptsOpenChange,
    nullableStringProp,
    numberProp,
} from '@/components/Meeting/meetingDialog';
import { MeetingFields } from '@/components/Meeting/MeetingFields';
import { MeetingImportInput } from '@/components/Meeting/MeetingImportInput';
import { SendingProgress } from '@/components/SendingProgress';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { clearRowErrors } from '@/lib/formErrors';
import { uploadProgress } from '@/lib/recordShow';
import { store } from '@/routes/meeting';
import type { RecordAddonAction, RecordModuleDefinition } from '@/types';

/** 業務の画面の操作の口が開く部品の props(Records の部品の表の型と同じ形) */
type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    values: RecordAddonAction['props'];
};

/** 音声の取り込みの値(一覧の口が渡す。blockedMessage = 先の止め方の文、受けられれば null) */
type AudioLimits = { maxBytes: number; blockedMessage: string | null };

/**
 * 先の止め方の文と取り込み(ファイルの欄の誤りは、先に止めている音声を選んだ誤りをサーバーの誤りより先に出す)。
 */
function CreateMeetingImport({
    audio,
    form,
}: {
    audio: AudioLimits;
    form: InertiaFormProps<CreateMeetingData>;
}) {
    const errors = form.errors as Record<string, string | undefined>;
    const { source } = form.data;
    const fileError =
        blockedFileError(form.data, audio.blockedMessage) ?? errors.file;

    return (
        <>
            {audio.blockedMessage !== null && (
                <MeetingAudioBlockedNotice message={audio.blockedMessage} />
            )}
            <MeetingImportInput
                maxBytes={audio.maxBytes}
                audioBlocked={audio.blockedMessage !== null}
                source={source}
                onSourceChange={(next) => form.setData('source', next)}
                text={form.data.text}
                onTextChange={(text) => form.setData('text', text)}
                onFileChange={(file) => form.setData('file', file)}
                errors={
                    source === 'file'
                        ? { file: fileError }
                        : { text: errors.text }
                }
            />
        </>
    );
}

/**
 * 欄の上の誤り・先の止め方の文・取り込み・注意書き・欄(ダイアログの中で縦に流す)。
 */
function CreateMeetingBody({
    module,
    audio,
    form,
}: {
    module: RecordModuleDefinition;
    audio: AudioLimits;
    form: InertiaFormProps<CreateMeetingData>;
}) {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <div className="-mx-6 flex min-h-0 flex-col gap-5 overflow-y-auto px-6 py-1">
            <UnshownErrors
                errors={namedErrors(module, errors)}
                shownKeys={shownErrorKeys(module, form.data)}
            />
            <CreateMeetingImport audio={audio} form={form} />
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

/** フォームの props(音声の上限と先の止め方は操作の props から、送っている間は親へ知らせる) */
type FormProps = {
    module: RecordModuleDefinition;
    audio: AudioLimits;
    onSendingChange: (sending: boolean) => void;
    onDone: () => void;
};

/**
 * ダイアログの中のフォーム(開くたびに作り直し、欄を既定値に戻す)。成功したら閉じる(入口が会議の一覧へ戻し、トーストを出す)。
 * 送っている間かを親へ知らせる(親が送る間の「閉じる」を捨てる)。先に止めている音声を選んでいれば送らず、ファイルの欄の入力へフォーカスを移す。
 */
function CreateMeetingForm(props: FormProps) {
    const { module, audio, onSendingChange, onDone } = props;
    const [initial] = useState(() => initialCreateMeetingData(module));
    const form = useForm<CreateMeetingData>(initial);
    const { formRef, focusFirstError, focusField } = useFocusFirstError();

    useReportSending(form.processing, onSendingChange);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (blockedFileError(form.data, audio.blockedMessage) !== null) {
            focusField(IMPORT_FILE_ID);

            return;
        }

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
            <CreateMeetingBody module={module} audio={audio} form={form} />
            {form.processing && (
                <SendingProgress
                    progress={uploadProgress(form.progress?.percentage)}
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
 * 受けている業務モジュールの定義(Records/Index の props module)から引き、音声の上限と先の止め方の文は操作の props から引く。
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
                    audio={{
                        maxBytes: numberProp(values, 'audioMaxBytes'),
                        blockedMessage: nullableStringProp(
                            values,
                            'audioBlockedMessage',
                        ),
                    }}
                    onSendingChange={setSending}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
