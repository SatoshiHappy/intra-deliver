/**
 * レコードの持ち主の型(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1・6.1。サーバーの
 * App\Queries\Records\RecordPageQuery::hold() が返す形)。
 */

import type { StatusTone } from './status';

/** 持ち主の画面へのリンク(持ち主の案内の値 = 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3) */
export type RecordHoldLink = { label: string; url: string };

/**
 * レコードの持ち主(詳細・編集のページの hold = 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1・6.1)。
 * state は持ち主の案内(見る人が持ち主の画面を閲覧できるときだけ。無ければ理由の 1 行だけ)。
 */
export type RecordHold = {
    reason: string;
    state: {
        link: RecordHoldLink;
        status: string;
        tone: StatusTone;
        suffix: string | null;
    } | null;
};
