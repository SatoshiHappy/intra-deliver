import { Info } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { MeetingFormError } from '@/components/Meeting/createMeetingForm';
import { INVALID_SELECTOR } from '@/components/RecordFields';
import { Button } from '@/components/ui/button';
import { DialogClose, DialogFooter } from '@/components/ui/dialog';
import { uploadProgress } from '@/lib/recordShow';

/** 録音の同意と個人情報の注意書き(docs の 設計/業務ツール/会議/公開デモと画面.md の 8.2 の 1 つ目) */
const CONSENT_NOTICE =
    '録音した全員の同意を得た音声だけを取り込んでください。個人情報を含めないでください。';

/** 外部の AI に送るものの注意書き(同じ 8.2 の 2 つ目) */
const EXTERNAL_AI_NOTICE =
    '取り込んだ音声とテキストと、会議の欄(件名・日時・閲覧者と閲覧組織の名前・アジェンダ・議事メモ)と追加の指示は、' +
    '文字起こしと議事録の作成のため、外部の AI(Google の Gemini API・Anthropic の API)に送られます。' +
    'AI に送った文の写しは 90 日で消え、音声は削除予定日を過ぎると消えます。';

/**
 * 会議のダイアログの注意書き(8.2。確認のチェックは求めない)。作成は 2 つ、再作成は外部の AI の 1 つ(withConsent = false)。
 */
export function MeetingNotices({ withConsent }: { withConsent: boolean }) {
    return (
        <ul
            aria-label="注意書き"
            className="grid gap-1.5 rounded-md border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground"
        >
            {(withConsent
                ? [CONSENT_NOTICE, EXTERNAL_AI_NOTICE]
                : [EXTERNAL_AI_NOTICE]
            ).map((notice) => (
                <li key={notice} className="flex gap-2">
                    <Info
                        className="mt-px size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{notice}</span>
                </li>
            ))}
        </ul>
    );
}

/**
 * 欄の上に出す誤り(送った後に最初の誤りへフォーカスを移せるよう tabIndex を付ける)。
 */
export function MeetingFormErrors({ errors }: { errors: MeetingFormError[] }) {
    return errors.map((error) => (
        <p
            key={error.key}
            role="alert"
            tabIndex={-1}
            className="text-sm text-destructive"
        >
            {error.message}
        </p>
    ));
}

/**
 * 送っている間の進み具合(Inertia の progress の percentage。割合が分からない間は値なし)。
 */
export function MeetingSendingProgress({
    percentage,
}: {
    percentage: number | undefined;
}) {
    const progress = uploadProgress(percentage);

    return (
        <div className="grid gap-1 text-xs">
            <progress
                className="h-1.5 w-full"
                max={100}
                value={progress.value ?? undefined}
                aria-label="送信の進み具合"
            />
            <span className="text-muted-foreground">{progress.text}</span>
        </div>
    );
}

/**
 * 送った後に、最初の誤り(欄の上の誤りか、誤りのある欄)へフォーカスを移す(業務の登録・編集のフォームと同じ)。
 * formRef をフォームに付け、送った結果が誤りのときに focusFirstError を呼ぶ。
 */
export function useFocusFirstError() {
    const formRef = useRef<HTMLFormElement>(null);
    const [request, setRequest] = useState(0);

    useEffect(() => {
        if (request > 0) {
            formRef.current
                ?.querySelector<HTMLElement>(INVALID_SELECTOR)
                ?.focus();
        }
    }, [request]);

    return {
        formRef,
        focusFirstError: () => setRequest((count) => count + 1),
    };
}

/**
 * 送っている間かを親へ知らせる(親が送る間の「閉じる」を捨てる)。外れる時は送っていない扱いに戻す。
 */
export function useReportSending(
    processing: boolean,
    onSendingChange: (sending: boolean) => void,
) {
    useEffect(() => {
        onSendingChange(processing);

        return () => onSendingChange(false);
    }, [processing, onSendingChange]);
}

/**
 * 「キャンセル」と送るボタン(送っている間はどちらも押せない)。
 */
export function MeetingDialogFooter({
    processing,
    submitLabel,
}: {
    processing: boolean;
    submitLabel: string;
}) {
    return (
        <DialogFooter>
            <DialogClose asChild>
                <Button type="button" variant="outline" disabled={processing}>
                    キャンセル
                </Button>
            </DialogClose>
            <Button type="submit" disabled={processing}>
                {submitLabel}
            </Button>
        </DialogFooter>
    );
}
