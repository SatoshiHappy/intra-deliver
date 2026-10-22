import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock, UnshownErrors } from '@/components/FormBlock';
import { namedErrors } from '@/components/Meeting/createMeetingForm';
import {
    MeetingAudioBlockedNotice,
    MeetingDialogFooter,
    MeetingNotices,
    useFocusFirstError,
    useReportSending,
} from '@/components/Meeting/MeetingDialogParts';
import {
    acceptsOpenChange,
    booleanProp,
    nullableStringProp,
    numberProp,
} from '@/components/Meeting/meetingDialog';
import {
    FROM_AUDIO_NOTE,
    regeneratePayload,
    regenerateView,
} from '@/components/Meeting/regenerateMinutesForm';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { regenerate } from '@/routes/meeting';
import type { RecordAddonAction, RecordModuleDefinition } from '@/types';

/** 業務の画面の操作の口が開く部品の props(Records の部品の表の型と同じ形) */
type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    values: RecordAddonAction['props'];
};

const INSTRUCTIONS_ID = 'meeting-regenerate-instructions';

/** ダイアログの文(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.3) */
const DESCRIPTION =
    '議事録の 5 欄を上書きします。前の値は更新履歴で確認できます。';

/**
 * 追加の指示(任意。上限まで入力の下に文字数を出す)。
 */
function InstructionsField({
    value,
    max,
    error,
    onChange,
}: {
    value: string;
    /** 最大の文字数(詳細の口が渡す、入口の上限) */
    max: number;
    error: string | undefined;
    onChange: (value: string) => void;
}) {
    return (
        <FormBlock id={INSTRUCTIONS_ID} label="追加の指示" error={error}>
            <div className="flex w-full flex-col gap-1">
                <CountedTextInput
                    id={INSTRUCTIONS_ID}
                    isMultiline
                    value={value}
                    max={max}
                    invalid={error !== undefined}
                    onChange={(event) => onChange(event.target.value)}
                    className="max-h-60 min-h-24"
                />
            </div>
        </FormBlock>
    );
}

/** 操作の props の値(書き起こしの有無・追加の指示の上限・音声から作り直すときの先の止め方の文) */
type RegenerateValues = {
    hasTranscript: boolean;
    instructionsMaxLength: number;
    audioBlockedMessage: string | null;
};

/**
 * 先の止め方の文と、追加の指示の欄(書き起こしが無ければ欄の代わりに音声から作り直す文)。
 */
function RegenerateInputs({
    values,
    instructions,
    error,
    onInstructionsChange,
}: {
    values: RegenerateValues;
    instructions: string;
    error: string | undefined;
    onInstructionsChange: (value: string) => void;
}) {
    const { audioBlockedMessage } = values;

    return (
        <>
            {audioBlockedMessage !== null && (
                <MeetingAudioBlockedNotice message={audioBlockedMessage} />
            )}
            {values.hasTranscript ? (
                <InstructionsField
                    value={instructions}
                    max={values.instructionsMaxLength}
                    error={error}
                    onChange={onInstructionsChange}
                />
            ) : (
                <p className="text-sm">{FROM_AUDIO_NOTE}</p>
            )}
        </>
    );
}

/** フォームの props(会議は詳細の画面から、値は操作の props から、送っている間は親へ知らせる) */
type FormProps = {
    module: RecordModuleDefinition;
    recordId: number;
    values: RegenerateValues;
    onSendingChange: (sending: boolean) => void;
    onDone: () => void;
};

/**
 * ダイアログの中のフォーム(開くたびに作り直す)。成功したら閉じる(入口が会議の詳細へ戻し、トーストを出す)。
 * 送っている間かを親へ知らせる(親が送る間の「閉じる」を捨てる)。書き起こしが無ければ追加の指示の欄を出さず送らない(音声から作り直す)。
 * 先の止め方の文があれば出し、送るボタンを押せない形にする。
 */
function RegenerateMinutesForm(props: FormProps) {
    const { module, recordId, values, onSendingChange, onDone } = props;
    const { hasTranscript } = values;
    const view = regenerateView(hasTranscript, values.audioBlockedMessage);
    const form = useForm({ instructions: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const { formRef, focusFirstError } = useFocusFirstError();

    useReportSending(form.processing, onSendingChange);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            regeneratePayload(hasTranscript, data.instructions),
        );
        form.submit(regenerate(recordId), {
            preserveScroll: true,
            onSuccess: onDone,
            onError: focusFirstError,
        });
    };

    return (
        <form ref={formRef} noValidate onSubmit={submit} className="grid gap-4">
            <DialogHeader>
                <DialogTitle>再作成</DialogTitle>
                <DialogDescription>{DESCRIPTION}</DialogDescription>
            </DialogHeader>
            <UnshownErrors
                errors={namedErrors(module, errors)}
                shownKeys={view.shownErrorKeys}
            />
            <RegenerateInputs
                values={values}
                instructions={form.data.instructions}
                error={errors.instructions}
                onInstructionsChange={(value) =>
                    form.setData('instructions', value)
                }
            />
            <MeetingNotices withConsent={false} />
            <MeetingDialogFooter
                processing={form.processing}
                submitLabel="再作成"
                submitDisabled={view.submitDisabled}
            />
        </form>
    );
}

/**
 * 「再作成」のダイアログ(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.3。会議の詳細の操作の口が開く)。議事録を作り直す入口へ送る
 * (会議は詳細の画面の props record から、書き起こしの有無・追加の指示の上限・先の止め方の文は操作の props から引く)。送っている間は閉じない。
 */
export function RegenerateMinutesDialog({ open, onOpenChange, values }: Props) {
    const { module, record } = usePage<{
        module: RecordModuleDefinition;
        record: { id: number };
    }>().props;
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
            <DialogContent className="sm:max-w-2xl">
                <RegenerateMinutesForm
                    module={module}
                    recordId={record.id}
                    values={{
                        hasTranscript: booleanProp(values, 'hasTranscript'),
                        instructionsMaxLength: numberProp(
                            values,
                            'instructionsMaxLength',
                        ),
                        audioBlockedMessage: nullableStringProp(
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
