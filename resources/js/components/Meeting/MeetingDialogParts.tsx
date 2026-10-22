import { CircleAlert, Info } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { DialogClose, DialogFooter } from '@/components/ui/dialog';
import { useFocusFirstInvalid } from '@/hooks/use-focus-first-invalid';

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
 * 音声の取り込みの先の止め方の文(公開デモと画面.md の 9.2・9.3。口が渡す文をそのまま、欄の上に先に出す)。
 */
export function MeetingAudioBlockedNotice({ message }: { message: string }) {
    return (
        <p className="flex gap-2 rounded-md border border-destructive/40 px-3 py-2.5 text-sm text-destructive">
            <CircleAlert
                className="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <span>{message}</span>
        </p>
    );
}

/**
 * 送った後に、最初の誤り(欄の上の誤りか、誤りのある欄)へフォーカスを移す(業務の登録・編集のフォームと同じ)。
 * formRef をフォームに付け、送った結果が誤りのときに focusFirstError を呼ぶ(focusField = 指した欄の入力へ直に移す)。
 */
export function useFocusFirstError() {
    const formRef = useRef<HTMLFormElement>(null);
    const [request, setRequest] = useState(0);

    useFocusFirstInvalid(formRef, request);

    return {
        formRef,
        focusFirstError: () => setRequest((count) => count + 1),
        // 残った誤りに先に当たらないよう、欄の入力を id で直に指す
        focusField: (id: string) =>
            formRef.current?.querySelector<HTMLElement>(`#${id}`)?.focus(),
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
 * 「キャンセル」と送るボタン(送っている間はどちらも押せない。送るボタンは submitDisabled の間も押せない)。
 */
export function MeetingDialogFooter({
    processing,
    submitLabel,
    submitDisabled = false,
}: {
    processing: boolean;
    submitLabel: string;
    submitDisabled?: boolean;
}) {
    return (
        <DialogFooter>
            <DialogClose asChild>
                <Button type="button" variant="outline" disabled={processing}>
                    キャンセル
                </Button>
            </DialogClose>
            <Button type="submit" disabled={processing || submitDisabled}>
                {submitLabel}
            </Button>
        </DialogFooter>
    );
}
