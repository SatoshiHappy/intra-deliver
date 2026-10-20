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
