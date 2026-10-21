import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    type CommentErrors,
    commentErrors,
    NO_COMMENT_ERRORS,
} from '@/lib/recordComments';
import type { RecordMentionCandidate } from '@/types';

/**
 * コメントの投稿(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 6 節・7 節): 本文と宛先を持ち、送る。成功は元の画面の同じ欄へ
 * 303 で戻り(開いた欄はクエリ panel のまま)、入力を空にする。誤り(body・mentions・mentions.<添字>)は欄の下に出す文言にして入力を保つ。
 */
export function useCommentPost(storeUrl: string) {
    const [body, setBody] = useState('');
    const [mentions, setMentions] = useState<RecordMentionCandidate[]>([]);
    const [errors, setErrors] = useState<CommentErrors>(NO_COMMENT_ERRORS);
    const [sending, setSending] = useState(false);

    const submit = () =>
        router.post(
            storeUrl,
            { body, mentions: mentions.map((person) => person.id) },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSending(true),
                onSuccess: () => {
                    setBody('');
                    setMentions([]);
                    setErrors(NO_COMMENT_ERRORS);
                },
                onError: (sent) => setErrors(commentErrors(sent)),
                onFinish: () => setSending(false),
            },
        );

    return { body, setBody, mentions, setMentions, errors, sending, submit };
}
