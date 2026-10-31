import { describe, expect, it } from 'vite-plus/test';
import type { WorkflowFlowVersionRow } from '@/types';
import {
    FLOW_TABS,
    currentVersionLines,
    flowHeadlineText,
    referrerSummaryText,
    stepHeadingText,
    versionLabel,
    versionStateLabel,
} from './flowDefinitionTexts';

function version(number: number, isCurrent: boolean): WorkflowFlowVersionRow {
    return {
        number,
        startDate: '2026-04-01',
        endDate: null,
        displayEndDate: null,
        isDisabled: false,
        isCurrent,
    };
}

describe('FLOW_TABS', () => {
    it('フロー図・版・参照者の順', () => {
        expect(FLOW_TABS.map((item) => [item.tab, item.label])).toEqual([
            ['diagram', 'フロー図'],
            ['versions', '版'],
            ['referrers', '参照者'],
        ]);
    });
});

describe('版の語', () => {
    it('版の名前と状態の語', () => {
        expect(versionLabel(3)).toBe('版 3');
        expect(versionStateLabel(false)).toBe('有効');
        expect(versionStateLabel(true)).toBe('ユーザー無効');
    });
});

describe('currentVersionLines', () => {
    it('今日の版があれば名前と期間、無ければ null', () => {
        expect(
            currentVersionLines({
                number: 3,
                startDate: '2026-10-01',
                endDate: null,
            }),
        ).toEqual({ name: '版 3', period: '2026-10-01 から 終了日なし' });
        expect(
            currentVersionLines({
                number: 2,
                startDate: '2026-04-01',
                endDate: '2026-09-30',
            })?.period,
        ).toBe('2026-04-01 から 2026-09-30');
        expect(currentVersionLines(null)).toBeNull();
    });
});

describe('referrerSummaryText', () => {
    it('無ければ「指定なし」', () => {
        expect(referrerSummaryText('経理部')).toBe('経理部');
        expect(referrerSummaryText(null)).toBe('指定なし');
    });
});

describe('flowHeadlineText', () => {
    it('今日の版があれば現在の版の番号を出す', () => {
        expect(
            flowHeadlineText('プロジェクト', [
                version(3, true),
                version(2, false),
            ]),
        ).toBe('業務モジュール：プロジェクト　現在の版は版 3 です。');
    });

    it('今日の版が無ければ「今日に有効な版はありません。」', () => {
        expect(flowHeadlineText('プロジェクト', [version(1, false)])).toBe(
            '業務モジュール：プロジェクト　今日に有効な版はありません。',
        );
    });
});

describe('stepHeadingText', () => {
    it('申請・承認・横配置・縦配置は名前に種類の語を添える', () => {
        expect(stepHeadingText('申請', 'apply')).toBe('申請 申請');
        expect(stepHeadingText('部長', 'approve')).toBe('部長 承認');
        expect(stepHeadingText('順次', 'serial')).toBe('順次 横配置');
        expect(stepHeadingText('関係部署の合議', 'parallel')).toBe(
            '関係部署の合議 縦配置',
        );
    });

    it('分岐・同期は名前だけ', () => {
        expect(stepHeadingText('予算', 'branch_start')).toBe('予算');
        expect(stepHeadingText('分岐終了', 'branch_end')).toBe('分岐終了');
        expect(stepHeadingText('同期開始', 'sync_start')).toBe('同期開始');
        expect(stepHeadingText('同期終了', 'sync_end')).toBe('同期終了');
    });
});
