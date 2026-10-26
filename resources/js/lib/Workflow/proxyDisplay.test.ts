import { describe, expect, it } from 'vite-plus/test';
import {
    PROXY_KIND_LABELS,
    PROXY_STATUS_BADGES,
    proxyAfterNoticeText,
    proxyPeriodText,
    proxyTargetText,
} from './proxyDisplay';

describe('期間の文', () => {
    it('終了日があれば「{開始日} から {終了日}」(終了日は最後の有効な日のまま)', () => {
        expect(proxyPeriodText('2026-10-15', '2026-10-31')).toBe(
            '2026/10/15 から 2026/10/31',
        );
    });

    it('終了日なしは「{開始日} から 終了日なし」', () => {
        expect(proxyPeriodText('2026-10-15', null)).toBe(
            '2026/10/15 から 終了日なし',
        );
    });
});

describe('状態の札・種類・対象の語', () => {
    it('状態は有効 = 承認待ちの色、開始前・終了 = 処理の結果の色', () => {
        expect(PROXY_STATUS_BADGES).toEqual({
            active: { label: '有効', tone: 'pending' },
            upcoming: { label: '開始前', tone: 'settled' },
            ended: { label: '終了', tone: 'settled' },
        });
    });

    it('種類は承認・申請、対象のフローが無ければすべてのフロー', () => {
        expect(PROXY_KIND_LABELS).toEqual({ approve: '承認', apply: '申請' });
        expect(proxyTargetText(null)).toBe('すべてのフロー');
        expect(proxyTargetText('課題の対応の承認')).toBe('課題の対応の承認');
    });
});

describe('注意の囲み「追加後の動き」の文', () => {
    it('承認は代理先の未処理に代理元の承認待ちが出る', () => {
        expect(proxyAfterNoticeText('approve', '吉田 達也', '高橋 誠')).toBe(
            '吉田 達也さんの未処理に、高橋 誠さんの承認待ちの申請が表示されます。吉田 達也さんへ通知が届きます。',
        );
    });

    it('申請は代理先が代理元として申請できるようになる', () => {
        expect(proxyAfterNoticeText('apply', '吉田 達也', '高橋 誠')).toBe(
            '吉田 達也さんが、高橋 誠さんとして申請できるようになります。吉田 達也さんへ通知が届きます。',
        );
    });
});
