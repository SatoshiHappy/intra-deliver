import type { NoticeRow, StatusTone } from '@/types';

/** 件数の札に出す上限(これを超えたら「99+」。docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.5) */
const UNREAD_BADGE_MAX = 99;

/**
 * 鈴の未読の件数の札の字(99 を超えたら「99+」)。
 */
export function unreadBadgeText(count: number): string {
    return count > UNREAD_BADGE_MAX ? `${UNREAD_BADGE_MAX}+` : String(count);
}

/**
 * 鈴の名前(読み上げ)。未読があれば件数を添える。
 */
export function bellLabel(unreadCount: number): string {
    return unreadCount > 0 ? `通知(未読 ${unreadCount} 件)` : '通知';
}

/** 1 行を作る語: 種類の札の語と色、文 */
export type NoticeTemplate = {
    label: string;
    tone: StatusTone;
    sentence: string;
};

/**
 * 通知の種類ごとの文と札の雛形(1 か所。docs の 設計/ワークフロー/案件の運用/通知.md の 3 節)。
 * 種類を足すときは case を足す。雛形に無い種類は default の「通知があります。」と札「通知」で行を出す。
 */
export function noticeTemplate(row: NoticeRow): NoticeTemplate {
    switch (row.type) {
        default:
            return {
                label: '通知',
                tone: 'settled',
                sentence: '通知があります。',
            };
    }
}

/**
 * 文の下の 1 行(対象の表示名、無ければ見えないときの 1 行、どちらも無ければ null = 出さない)。
 */
export function noticeSubLine(row: NoticeRow): string | null {
    return row.targetLabel ?? row.unavailableText;
}
