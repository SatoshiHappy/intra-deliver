import { nameOrCode } from '@/lib/nameOrCode';
import { formatDate } from '@/lib/recordValues';
import type { NoticeRow, StatusTone, WorkflowProxyKind } from '@/types';

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
 * 種類を足すときは case を足す(ワークフローの種類は {@link workflowTemplate}、会議の種類は {@link meetingTemplate})。雛形に無い種類は
 * 「通知があります。」と札「通知」で行を出す。
 */
export function noticeTemplate(row: NoticeRow): NoticeTemplate {
    const workflow = workflowTemplate(row);

    if (workflow !== null) {
        return workflow;
    }

    switch (row.type) {
        case 'records.comment':
            return {
                label: 'コメント',
                tone: 'settled',
                sentence: commentSentence(row),
            };
        default:
            return meetingTemplate(row) ?? UNKNOWN_TEMPLATE;
    }
}

/**
 * ワークフローの通知の雛形(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節)。ワークフローの種類でなければ null。
 */
function workflowTemplate(row: NoticeRow): NoticeTemplate | null {
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
        case 'workflow.retarget':
            return {
                label: '処理担当者の変更',
                tone: 'returned',
                sentence: `${caseName}　${stepName}の処理担当者に指定されました。`,
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
        case 'workflow.proxy':
            return proxyTemplate(row);
        default:
            return null;
    }
}

/** 雛形に無い種類の行 */
const UNKNOWN_TEMPLATE: NoticeTemplate = {
    label: '通知',
    tone: 'settled',
    sentence: '通知があります。',
};

/**
 * 会議の通知の札と文(docs の 設計/業務ツール/会議/通知と削除予定日.md の 6.1・6.4)。会議の種類でなければ null。
 */
function meetingTemplate(row: NoticeRow): NoticeTemplate | null {
    const title = textParam(row, 'title');

    switch (row.type) {
        case 'meeting.minutes_created':
            return {
                label: '議事録の完了',
                tone: 'settled',
                sentence: `${title}　議事録を作成しました。`,
            };
        case 'meeting.minutes_regenerated':
            return {
                label: '議事録の再作成の完了',
                tone: 'settled',
                sentence: `${title}　議事録を再作成しました。`,
            };
        case 'meeting.transcription_failed':
            return {
                label: '文字起こしの失敗',
                tone: 'rejected',
                sentence: `${title}　文字起こしに失敗しました。${meetingReasonSentence(row)}`,
            };
        case 'meeting.minutes_failed':
            return {
                label: '議事録の失敗',
                tone: 'rejected',
                sentence: `${title}　議事録の作成に失敗しました。${meetingReasonSentence(row)}`,
            };
        default:
            return null;
    }
}

/** 会議の失敗の、種類によらない理由の文(理由 → 文) */
const MEETING_REASONS = new Map([
    ['budget', 'AI の利用の上限に達しました。'],
    ['too_long', '長すぎるため処理できませんでした。'],
    [
        'record_unavailable',
        '会議を更新できなかったため、議事録を書けませんでした。',
    ],
]);

/** 会議の失敗の、その種類だけの理由の文(「種類 理由」→ 文) */
const MEETING_KIND_REASONS = new Map([
    [
        'meeting.transcription_failed audio_unusable',
        '音声から書き起こせませんでした。音声を確かめて、音声から作成し直してください。',
    ],
    [
        'meeting.minutes_failed truncated',
        '議事録が長すぎました。再作成の追加の指示で短くするよう頼んでください。',
    ],
]);

/**
 * 会議の失敗の理由の文(通知の種類と理由で選ぶ = 6.4)。種類に合わない理由と知らない理由は「ほか」の文。
 */
function meetingReasonSentence(row: NoticeRow): string {
    const reason = textParam(row, 'reason');

    return (
        MEETING_REASONS.get(reason) ??
        MEETING_KIND_REASONS.get(`${row.type} ${reason}`) ??
        '時間をおいて再作成してください。'
    );
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

/** 代理の別の語(代理の通知の文) */
const PROXY_KIND_WORDS: Record<WorkflowProxyKind, string> = {
    approve: '承認',
    apply: '申請',
};

/**
 * 代理の通知の札(処理の結果の色)と文。
 */
function proxyTemplate(row: NoticeRow): NoticeTemplate {
    return { label: '代理', tone: 'settled', sentence: proxySentence(row) };
}

/**
 * 代理の通知の文(フローの指定があればフロー名、終了日が無ければ終了日なし。日付は最後の有効な日を YYYY/MM/DD で出す)。
 */
function proxySentence(row: NoticeRow): string {
    const principal = textParam(row, 'principal_name');
    const flowName = textParam(row, 'flow_name');
    const target = flowName === '' ? '' : `${flowName}の`;
    const kind = proxyKindWord(row.params.kind);
    const start = formatDate(textParam(row, 'start_date'));
    const end = textParam(row, 'end_date');
    const period =
        end === ''
            ? `期間は ${start} から、終了日なしです。`
            : `期間は ${start} から ${formatDate(end)} までです。`;

    return `${principal}さんの${target}${kind}の代理先に設定されました。${period}`;
}

/**
 * 代理の別の語(知らない値は空 = ほかの文の値と同じ扱い)。
 */
function proxyKindWord(value: NoticeRow['params'][string]): string {
    return value === 'apply' || value === 'approve'
        ? PROXY_KIND_WORDS[value]
        : '';
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
