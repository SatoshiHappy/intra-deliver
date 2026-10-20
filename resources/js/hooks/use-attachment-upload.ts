import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';

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
 * まとめて送り、送っている間は押せなくする。成功は元の画面へ 303 で戻る(開いた欄はクエリ panel のまま)。誤りは欄の上に出す。
 */
export function useAttachmentUpload(uploadUrl: string) {
    const input = useRef<HTMLInputElement>(null);
    const [sending, setSending] = useState(false);
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
                onStart: () => setSending(true),
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

    return { input, sending, errors, send };
}
