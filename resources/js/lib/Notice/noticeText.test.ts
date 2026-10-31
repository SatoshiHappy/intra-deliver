import { describe, expect, it } from 'vite-plus/test';
import type { NoticeRow } from '@/types';
import {
    bellLabel,
    noticeSubLine,
    noticeTemplate,
    unreadBadgeText,
} from './noticeText';

function row(overrides: Partial<NoticeRow> = {}): NoticeRow {
    return {
        id: 1,
        type: 'unknown.kind',
        params: {},
        actor: null,
        createdAt: '2026-10-10T03:00:00+00:00',
        readAt: null,
        url: null,
        targetLabel: null,
        unavailableText: null,
        ...overrides,
    };
}

describe('unreadBadgeText', () => {
    it('99 までは件数のまま、100 以上は 99+', () => {
        expect(unreadBadgeText(1)).toBe('1');
        expect(unreadBadgeText(99)).toBe('99');
        expect(unreadBadgeText(100)).toBe('99+');
        expect(unreadBadgeText(1234)).toBe('99+');
    });
});

describe('bellLabel', () => {
    it('未読が無ければ「通知」、あれば件数を添える(99 を超えても実際の件数)', () => {
        expect(bellLabel(0)).toBe('通知');
        expect(bellLabel(3)).toBe('通知(未読 3 件)');
        expect(bellLabel(120)).toBe('通知(未読 120 件)');
    });
});

describe('noticeTemplate', () => {
    it('雛形に無い種類は「通知があります。」と札「通知」', () => {
        expect(noticeTemplate(row())).toEqual({
            label: '通知',
            tone: 'settled',
            sentence: '通知があります。',
        });
        expect(noticeTemplate(row({ type: '' })).sentence).toBe(
            '通知があります。',
        );
    });
});

describe('noticeTemplate(レコードのコメント)', () => {
    it('起こした人の名前(無ければコード)で、コメントの宛先に指定された文(札は「コメント」)', () => {
        expect(
            noticeTemplate(
                row({
                    type: 'records.comment',
                    actor: { name: '中村 美咲', code: 'u0002' },
                }),
            ),
        ).toEqual({
            label: 'コメント',
            tone: 'settled',
            sentence: '中村 美咲さんがコメントの宛先にあなたを指定しました。',
        });
        expect(
            noticeTemplate(
                row({
                    type: 'records.comment',
                    actor: { name: null, code: 'u0002' },
                }),
            ).sentence,
        ).toBe('u0002さんがコメントの宛先にあなたを指定しました。');
    });
});

/** 件名「定例会議」の会議の通知の行の札と文 */
const meetingNotice = (type: string, params: NoticeRow['params']) =>
    noticeTemplate(row({ type, params: { title: '定例会議', ...params } }));

describe('noticeTemplate(会議の完了と失敗)', () => {
    const of = meetingNotice;

    it('完了は処理の結果の色、失敗は否認の色で、件名の後に文', () => {
        expect(of('meeting.minutes_created', {})).toEqual({
            label: '議事録の完了',
            tone: 'settled',
            sentence: '定例会議　議事録を作成しました。',
        });
        expect(of('meeting.minutes_regenerated', {})).toEqual({
            label: '議事録の再作成の完了',
            tone: 'settled',
            sentence: '定例会議　議事録を再作成しました。',
        });
        expect(
            of('meeting.transcription_failed', { reason: 'budget' }),
        ).toEqual({
            label: '文字起こしの失敗',
            tone: 'rejected',
            sentence:
                '定例会議　文字起こしに失敗しました。AI の利用の上限に達しました。',
        });
        expect(of('meeting.minutes_failed', { reason: 'budget' })).toEqual({
            label: '議事録の失敗',
            tone: 'rejected',
            sentence:
                '定例会議　議事録の作成に失敗しました。AI の利用の上限に達しました。',
        });
    });
});

describe('noticeTemplate(会議の失敗の、種類によらない理由の文)', () => {
    const of = meetingNotice;

    it('上限・長すぎる・会議を更新できない・ほか', () => {
        const sentence = (type: string, reason: string) =>
            of(type, { reason }).sentence;

        for (const type of [
            'meeting.transcription_failed',
            'meeting.minutes_failed',
        ]) {
            expect(sentence(type, 'too_long')).toContain(
                'に失敗しました。長すぎるため処理できませんでした。',
            );
            expect(sentence(type, 'record_unavailable')).toContain(
                'に失敗しました。会議を更新できなかったため、議事録を書けませんでした。',
            );
            expect(sentence(type, 'other')).toContain(
                'に失敗しました。時間をおいて再作成してください。',
            );
        }
    });
});

describe('noticeTemplate(会議の失敗の、種類だけの理由の文)', () => {
    const of = meetingNotice;

    it('音声の理由は文字起こしの失敗、長すぎた理由は議事録の失敗だけの文で、合わない種類と知らない理由は「ほか」の文', () => {
        const sentence = (type: string, reason: string | null) =>
            of(type, { reason }).sentence;

        expect(sentence('meeting.transcription_failed', 'audio_unusable')).toBe(
            '定例会議　文字起こしに失敗しました。音声から書き起こせませんでした。音声を確かめて、音声から作成し直してください。',
        );
        expect(sentence('meeting.minutes_failed', 'truncated')).toBe(
            '定例会議　議事録の作成に失敗しました。議事録が長すぎました。再作成の追加の指示で短くするよう頼んでください。',
        );
        expect(sentence('meeting.minutes_failed', 'audio_unusable')).toBe(
            '定例会議　議事録の作成に失敗しました。時間をおいて再作成してください。',
        );
        expect(sentence('meeting.transcription_failed', 'truncated')).toBe(
            '定例会議　文字起こしに失敗しました。時間をおいて再作成してください。',
        );
        expect(sentence('meeting.minutes_failed', 'unknown')).toBe(
            '定例会議　議事録の作成に失敗しました。時間をおいて再作成してください。',
        );
        expect(sentence('meeting.minutes_failed', null)).toBe(
            '定例会議　議事録の作成に失敗しました。時間をおいて再作成してください。',
        );
    });
});

describe('noticeTemplate(会議の件名が空・知らない種類)', () => {
    const of = meetingNotice;

    it('件名が空・無いときは件名の部分が空のまま、区切りの空白から始まる', () => {
        expect(of('meeting.minutes_created', { title: '' }).sentence).toBe(
            '　議事録を作成しました。',
        );
        expect(
            noticeTemplate(row({ type: 'meeting.minutes_regenerated' }))
                .sentence,
        ).toBe('　議事録を再作成しました。');
    });

    it('会議の知らない種類は雛形に無い種類と同じ行', () => {
        expect(of('meeting.unknown', {})).toEqual({
            label: '通知',
            tone: 'settled',
            sentence: '通知があります。',
        });
    });
});

describe('noticeSubLine', () => {
    it('対象の表示名、無ければ見えないときの 1 行、どちらも無ければ null', () => {
        expect(
            noticeSubLine(
                row({ targetLabel: '見積', unavailableText: '不可' }),
            ),
        ).toBe('見積');
        expect(noticeSubLine(row({ unavailableText: '不可' }))).toBe('不可');
        expect(noticeSubLine(row())).toBeNull();
    });
});
