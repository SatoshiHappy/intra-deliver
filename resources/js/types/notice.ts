/**
 * 画面内通知の型(docs の 設計/ワークフロー/案件の運用/通知.md の 7 節。サーバーの App\Queries\Notice が返す形)。
 * 日時は時差つきの文字列(画面は Asia/Tokyo で出す)。
 */

/** 通知の 1 行(鈴の中と通知の一覧の表が同じ形を使う) */
export type NoticeRow = {
    id: number;
    /** 通知の種類(例: 'workflow.process_request')。文と札の語は種類から画面が作る */
    type: string;
    params: Record<string, string | number | boolean | null>;
    /** 起こした人(名前は今日・ロケールの値。無ければコード)。起こした人がいない通知は null */
    actor: { name: string | null; code: string } | null;
    createdAt: string;
    readAt: string | null;
    /** 押した時の移り先。null = 移り先なし */
    url: string | null;
    /** 文の下の対象の表示名。null = 出さない */
    targetLabel: string | null;
    /** 移り先が無く対象があるときの 1 行 */
    unavailableText: string | null;
};

/** 鈴の最新 10 件の口(notice.latest)の JSON */
export type NoticeLatestResult = { rows: NoticeRow[] };

/** どの画面にも共有する未読の数(ゲストには null) */
export type NoticeSummary = { unreadCount: number };

export type NoticeFilter = 'all' | 'unread';

/** 通知の一覧のページの props */
export type NoticeIndexProps = {
    query: { filter: NoticeFilter };
    counts: { unread: number };
    notices: {
        data: NoticeRow[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
};
