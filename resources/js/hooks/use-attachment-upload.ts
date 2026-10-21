import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { uploadProgress } from '@/lib/recordShow';

/** 送った添付の誤り 1 つ(key = 誤りのキー。同じ文言が重なっても一意) */
type AttachmentError = { key: string; message: string };

/**
 * 送った添付の誤り(キー attachments と attachments.<添字>)を、欄の上に出す並びにする。
 */
function attachmentErrors(errors: Record<string, string>): AttachmentError[] {
    return Object.entries(errors)
        .filter(
            ([key]) => key === 'attachments' || key.startsWith('attachments.'),
        )
        .map(([key, message]) => ({ key, message }));
}

/**
 * 「ファイルを追加」の送信(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 8 節・9 節): 選んだファイルを
 * まとめて送り、送っている間は押せなくして進み具合(Inertia の onProgress の percentage)を返す。成功は元の画面へ 303 で戻る(開いた欄はクエリ panel のまま)。誤りは欄の上に出す。
 */
export function useAttachmentUpload(uploadUrl: string) {
    const input = useRef<HTMLInputElement>(null);
    const [sending, setSending] = useState(false);
    const [percentage, setPercentage] = useState<number | undefined>();
    const [errors, setErrors] = useState<AttachmentError[]>([]);

    const send = (files: FileList | null) => {
        if (files === null || files.length === 0) {
            return;
        }

        router.post(
            uploadUrl,
            { attachments: Array.from(files) },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setPercentage(undefined);
                    setSending(true);
                },
                onProgress: (event) => setPercentage(event?.percentage),
                onSuccess: () => setErrors([]),
                onError: (sent) => setErrors(attachmentErrors(sent)),
                onFinish: () => {
                    setSending(false);

                    if (input.current !== null) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    // 送っている間だけ進み具合を出す(割合が分からない間は値なし)
    const progress = sending ? uploadProgress(percentage) : null;

    return { input, sending, progress, errors, send };
}
