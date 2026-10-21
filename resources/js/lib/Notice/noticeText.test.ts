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

describe('noticeTemplate(ワークフローの処理依頼・担当変更)', () => {
    const base = { case_number: 12, case_name: '出張申請' };
    const of = (type: string, params: NoticeRow['params']) =>
        noticeTemplate(row({ type, params: { ...base, ...params } }));

    it('処理依頼は申請のステップなら再申請、承認なら承認の依頼(札は承認待ちの色)', () => {
        expect(
            of('workflow.process_request', {
                step_name: '申請',
                step_kind: 'apply',
            }),
        ).toEqual({
            label: '処理依頼',
            tone: 'pending',
            sentence: '出張申請　再申請をお願いします。',
        });
        expect(
            of('workflow.process_request', {
                step_name: '関係部署の合議',
                step_kind: 'approve',
            }).sentence,
        ).toBe('出張申請　承認をお願いします。（関係部署の合議）');
        expect(
            of('workflow.process_request', {
                step_name: '課長の承認',
                step_kind: 'approve',
            }).sentence,
        ).toBe('出張申請　承認をお願いします。（課長の承認）');
    });

    it('担当変更は差戻しの色', () => {
        expect(
            of('workflow.transfer', {
                step_name: '課長',
                step_kind: 'approve',
                comment: null,
            }),
        ).toEqual({
            label: '担当変更',
            tone: 'returned',
            sentence: '出張申請　課長の処理担当者が変更されました。',
        });
    });
});

describe('noticeTemplate(ワークフローの処理結果・確認依頼・連絡)', () => {
    const base = { case_number: 12, case_name: '出張申請' };
    const of = (type: string, params: NoticeRow['params']) =>
        noticeTemplate(row({ type, params: { ...base, ...params } }));

    it('処理結果は完了の理由ごとの文(処理の結果の色)', () => {
        const sentence = (reason: string) =>
            of('workflow.result', { completion_reason: reason }).sentence;

        expect(sentence('approved')).toBe('出張申請　承認されて完了しました。');
        expect(sentence('approved_early')).toBe(
            '出張申請　承認終了で完了しました。',
        );
        expect(sentence('rejected')).toBe('出張申請　否認されました。');
        expect(
            of('workflow.result', { completion_reason: 'approved' }),
        ).toMatchObject({ label: '処理結果', tone: 'settled' });
    });

    it('確認依頼は承認待ちの色', () => {
        expect(of('workflow.confirm_request', { step_name: '課長' })).toEqual({
            label: '確認依頼',
            tone: 'pending',
            sentence: '出張申請　確認をお願いします。',
        });
    });

    it('連絡はひと言があればひと言、無ければ連絡があった旨(処理の結果の色)', () => {
        expect(of('workflow.contact', { message: '急ぎです' })).toEqual({
            label: '連絡',
            tone: 'settled',
            sentence: '出張申請　急ぎです',
        });
        expect(of('workflow.contact', { message: null }).sentence).toBe(
            '出張申請について連絡がありました。',
        );
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
