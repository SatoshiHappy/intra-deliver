import { RecordCommentForm } from '@/components/RecordCommentForm';
import { RecordCommentList } from '@/components/RecordCommentList';
import adminComments from '@/routes/records/admin/comments';
import comments from '@/routes/records/data/comments';
import type { RecordCommentRow, RecordPanelContent } from '@/types';

type Content = Extract<RecordPanelContent, { kind: 'comments' }>;

type Props = {
    content: Content;
    /** レコードの詳細の URL の引数(業務モジュールのコードとレコードの id) */
    route: { module: string; record: number };
    loading: boolean;
    onMore: (beforeId: number) => void;
};

/**
 * サイドパネルの「コメント」の欄(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 7 節): 上に入力(ゲストは入力の代わりに 1 行)、
 * 下に一覧。ルートの名前はここ(呼び手)が持ち、部品へ URL で渡す。自分の分の削除は業務の経路、ほかの人の分は管理の経路。
 */
export function RecordCommentsPane({ content, route, loading, onMore }: Props) {
    const deleteActionOf = (row: RecordCommentRow) =>
        row.mine
            ? comments.destroy({ ...route, comment: row.id })
            : adminComments.destroy({ comment: row.id });

    return (
        <div className="grid gap-4">
            {content.canPost ? (
                <RecordCommentForm
                    storeUrl={comments.store(route).url}
                    candidatesUrl={comments.candidates(route).url}
                />
            ) : (
                <p className="text-muted-foreground">
                    コメントを追加するには、ユーザーを選択してください。
                </p>
            )}
            <RecordCommentList
                rows={content.rows}
                hasMore={content.hasMore}
                canDeleteOthers={content.canDeleteOthers}
                deleteActionOf={deleteActionOf}
                loading={loading}
                onMore={onMore}
            />
        </div>
    );
}
