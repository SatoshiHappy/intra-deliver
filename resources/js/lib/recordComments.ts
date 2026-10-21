import type { RecordCommentRow } from '@/types';

/** 宛先の人数の上限(PostRecordComment::MENTIONS_MAX と同じ値。超えると 422 なので選べなくする) */
export const MENTIONS_MAX = 10;

/** 投稿の誤り(本文の下と宛先の下に出す文言) */
export type CommentErrors = { body: string | null; mentions: string[] };

export const NO_COMMENT_ERRORS: CommentErrors = { body: null, mentions: [] };

/**
 * 投稿の 422 のキー(body・mentions・mentions.<添字>)を、本文の誤りと宛先の誤りの並びにする(ほかのキーは無視する)。
 */
export function commentErrors(errors: Record<string, string>): CommentErrors {
    return {
        body: errors.body ?? null,
        // 宛先の誤りは文言が 1 つなので、同じ文言は 1 件にまとめる
        mentions: [
            ...new Set(
                Object.entries(errors)
                    .filter(
                        ([key]) =>
                            key === 'mentions' || key.startsWith('mentions.'),
                    )
                    .map(([, message]) => message),
            ),
        ],
    };
}

/**
 * 行の「削除」を出すか(削除の印の行は出さない。自分のコメントか、コメントの管理の権限がある人 = 欄の canDeleteOthers)。
 */
export function canDeleteComment(
    row: Pick<RecordCommentRow, 'deleted' | 'mine'>,
    canDeleteOthers: boolean,
): boolean {
    return row.deleted === null && (row.mine || canDeleteOthers);
}

/**
 * 宛先の欄に出す候補の状態の 1 行(候補が出ていなければ null)。語が空の間は入力を促す(候補の口は語が空だと空を返す)。
 */
export function candidateNotice(
    query: string,
    state: { waiting: boolean; failed: boolean; found: number | null },
): string | null {
    if (query.trim() === '') {
        return '検索する語を入力してください';
    }

    if (state.failed) {
        return '候補を取得できませんでした';
    }

    if (state.found === null || state.waiting) {
        return '検索中…';
    }

    return state.found === 0 ? '該当する候補はありません' : null;
}
