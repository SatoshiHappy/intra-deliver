import { formErrorId } from '@/components/FormBlock';
import { RecordMentionPicker } from '@/components/RecordMentionPicker';
import { RichTextInput } from '@/components/RichTextInput';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useCommentPost } from '@/hooks/use-comment-post';

type Props = {
    /** コメントを投稿する URL(成功は元の画面へ 303 で戻る。開いた欄はクエリ panel のまま) */
    storeUrl: string;
    /** 宛先の候補の口の URL */
    candidatesUrl: string;
};

/** 欄の先頭の説明の 1 行 */
const GUIDE =
    '宛先に選択したユーザーだけに、画面内の通知が送信されます。書いたコメントは編集できません。削除できるのは自分のコメントと、システム管理者だけです。';

/** 本文の入力の id(誤りの文言の要素の id もここから作る) */
const BODY_ID = 'record-comment-body';

/** 誤りの文言の並び(欄の下。読み上げに出す。id は入力の aria-describedby が指す) */
function ErrorLines({ messages, id }: { messages: string[]; id?: string }) {
    return messages.length === 0 ? null : (
        <ul
            id={id}
            role="alert"
            className="grid gap-0.5 text-sm text-destructive"
        >
            {messages.map((message) => (
                <li key={message}>{message}</li>
            ))}
        </ul>
    );
}

/**
 * コメントの入力(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 7 節。順 = 説明の 1 行 → 宛先 → 本文 → 右寄せの「投稿」)。
 * 送ると元の画面の同じ欄へ戻り、成功すれば入力を空にする。誤り(本文・宛先)は欄の下に出し、入力を保つ。
 */
export function RecordCommentForm({ storeUrl, candidatesUrl }: Props) {
    const post = useCommentPost(storeUrl);
    const { errors, sending } = post;

    return (
        <form
            aria-label="コメントの入力"
            className="grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                post.submit();
            }}
        >
            <p className="text-xs text-muted-foreground">{GUIDE}</p>
            <Label>宛先</Label>
            <RecordMentionPicker
                value={post.mentions}
                onChange={post.setMentions}
                candidatesUrl={candidatesUrl}
                disabled={sending}
            />
            <p className="text-xs text-muted-foreground">
                このレコードを閲覧できるユーザーだけを選択できます。組織は選択できません。
            </p>
            <ErrorLines messages={errors.mentions} />
            <RichTextInput
                id={BODY_ID}
                ariaLabel="コメント"
                placeholder="コメントを入力"
                value={post.body}
                onChange={post.setBody}
                max={null}
                invalid={errors.body !== null}
                isRequiredNow
                disabled={sending}
            />
            <ErrorLines
                id={formErrorId(BODY_ID)}
                messages={errors.body === null ? [] : [errors.body]}
            />
            <div className="flex justify-end">
                <Button type="submit" disabled={sending}>
                    投稿
                </Button>
            </div>
        </form>
    );
}
