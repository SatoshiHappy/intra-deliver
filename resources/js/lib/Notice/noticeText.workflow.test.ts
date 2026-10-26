import { describe, expect, it } from 'vite-plus/test';
import type { NoticeRow } from '@/types';
import { noticeTemplate } from './noticeText';

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

/** 案件「出張申請」(番号 12)のワークフローの通知の行の札と文 */
const workflowCase = { case_number: 12, case_name: '出張申請' };
const workflowNotice = (type: string, params: NoticeRow['params']) =>
    noticeTemplate(row({ type, params: { ...workflowCase, ...params } }));

describe('noticeTemplate(ワークフローの処理依頼・担当変更)', () => {
    const of = workflowNotice;

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

describe('noticeTemplate(ワークフローの処理担当者の変更)', () => {
    const of = workflowNotice;

    it('処理担当者の変更は担当変更と同じ差戻しの色', () => {
        expect(
            of('workflow.retarget', {
                step_name: '関係部署の合議',
                step_kind: 'approve',
                comment: '異動のため',
            }),
        ).toEqual({
            label: '処理担当者の変更',
            tone: 'returned',
            sentence: '出張申請　関係部署の合議の処理担当者に指定されました。',
        });
    });
});

describe('noticeTemplate(ワークフローの処理結果・確認依頼・連絡)', () => {
    const of = workflowNotice;

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

describe('noticeTemplate(ワークフローの代理)', () => {
    const proxyNotice = (params: NoticeRow['params']) =>
        noticeTemplate(row({ type: 'workflow.proxy', params }));
    const base = {
        principal_name: '高橋 誠',
        kind: 'approve',
        flow_name: null,
        start_date: '2026-10-13',
        end_date: null,
    };

    it('すべてのフロー・終了日なしの承認の代理(処理の結果の色)', () => {
        expect(proxyNotice(base)).toEqual({
            label: '代理',
            tone: 'settled',
            sentence:
                '高橋 誠さんの承認の代理先に設定されました。期間は 2026/10/13 から、終了日なしです。',
        });
    });

    it('すべてのフロー・終了日ありの申請の代理', () => {
        expect(
            proxyNotice({ ...base, kind: 'apply', end_date: '2026-10-31' })
                .sentence,
        ).toBe(
            '高橋 誠さんの申請の代理先に設定されました。期間は 2026/10/13 から 2026/10/31 までです。',
        );
    });

    it('フローを指定した代理は「{フロー名}の」を付ける(終了日なし・あり)', () => {
        const flow = { ...base, flow_name: 'プロジェクト計画の承認' };

        expect(proxyNotice(flow).sentence).toBe(
            '高橋 誠さんのプロジェクト計画の承認の承認の代理先に設定されました。期間は 2026/10/13 から、終了日なしです。',
        );
        expect(proxyNotice({ ...flow, end_date: '2026-10-18' }).sentence).toBe(
            '高橋 誠さんのプロジェクト計画の承認の承認の代理先に設定されました。期間は 2026/10/13 から 2026/10/18 までです。',
        );
    });
});
