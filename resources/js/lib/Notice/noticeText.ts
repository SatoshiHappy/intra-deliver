import { nameOrCode } from '@/lib/nameOrCode';
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
    const caseName = textParam(row, 'case_name');
    const stepName = textParam(row, 'step_name');

    switch (row.type) {
        case 'workflow.process_request':
            return {
                label: '処理依頼',
                tone: 'pending',
                sentence: processRequestSentence(row),
            };
        case 'workflow.transfer':
            return {
                label: '担当変更',
                tone: 'returned',
                sentence: `${caseName}　${stepName}の処理担当者が変更されました。`,
            };
        case 'workflow.result':
            return {
                label: '処理結果',
                tone: 'settled',
                sentence: `${caseName}　${resultPhrase(row.params.completion_reason)}`,
            };
        case 'workflow.confirm_request':
            return {
                label: '確認依頼',
                tone: 'pending',
                sentence: `${caseName}　確認をお願いします。`,
            };
        case 'workflow.contact':
            return {
                label: '連絡',
                tone: 'settled',
                sentence: contactSentence(row),
            };
        case 'records.comment':
            return {
                label: 'コメント',
                tone: 'settled',
                sentence: commentSentence(row),
            };
        default:
            return {
                label: '通知',
                tone: 'settled',
                sentence: '通知があります。',
            };
    }
}

/**
 * コメントの宛先の文(起こした人は名前、無ければコード。docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 3 節)。
 */
function commentSentence(row: NoticeRow): string {
    const actor = row.actor === null ? '' : nameOrCode(row.actor);

    return `${actor}さんがコメントの宛先にあなたを指定しました。`;
}

/**
 * 処理依頼の文(申請のステップなら再申請、承認のステップなら承認の依頼)。ステップ名は括弧で後ろに置く(「〜の承認」という名前でも語が重ならない)。
 */
function processRequestSentence(row: NoticeRow): string {
    const caseName = textParam(row, 'case_name');

    return row.params.step_kind === 'apply'
        ? `${caseName}　再申請をお願いします。`
        : `${caseName}　承認をお願いします。（${textParam(row, 'step_name')}）`;
}

/**
 * 連絡の文(ひと言が無ければ連絡があった旨)。
 */
function contactSentence(row: NoticeRow): string {
    const caseName = textParam(row, 'case_name');
    const message = textParam(row, 'message');

    return message === ''
        ? `${caseName}について連絡がありました。`
        : `${caseName}　${message}`;
}

/**
 * 文の値の文字列(無い・文字列でなければ空)。
 */
function textParam(row: NoticeRow, key: string): string {
    const value = row.params[key];

    return typeof value === 'string' ? value : '';
}

/**
 * 処理結果の文の後半(完了の理由ごと。通知.md の 3 節)。rejected(否認・合議が可決できなくなった)は既定の枝。
 */
function resultPhrase(reason: NoticeRow['params'][string]): string {
    switch (reason) {
        case 'approved':
            return '承認されて完了しました。';
        case 'approved_early':
            return '承認終了で完了しました。';
        default:
            return '否認されました。';
    }
}

/**
 * 文の下の 1 行(対象の表示名、無ければ見えないときの 1 行、どちらも無ければ null = 出さない)。
 */
export function noticeSubLine(row: NoticeRow): string | null {
    return row.targetLabel ?? row.unavailableText;
}
