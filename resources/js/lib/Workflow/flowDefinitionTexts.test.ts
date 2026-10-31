import { describe, expect, it } from 'vite-plus/test';
import type { WorkflowFlowVersionRow } from '@/types';
import {
    FLOW_TABS,
    currentVersionLines,
    flowHeadlineText,
    moduleNameText,
    referrerSummaryText,
    stepKindWord,
    versionLabel,
    versionOptionLabel,
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
    it('フロー図・版・設定の順', () => {
        expect(FLOW_TABS.map((item) => [item.tab, item.label])).toEqual([
            ['diagram', 'フロー図'],
            ['versions', '版'],
            ['settings', '設定'],
        ]);
    });
});

describe('版の語', () => {
    it('版の名前と状態の語', () => {
        expect(versionLabel(3)).toBe('版 3');
        expect(versionStateLabel(false)).toBe('有効');
        expect(versionStateLabel(true)).toBe('無効');
    });

    it('版の選択の選択肢は番号と開始日', () => {
        expect(versionOptionLabel(version(3, true))).toBe(
            '版 3　2026/04/01 から',
        );
    });
});

describe('moduleNameText', () => {
    it('申請対象の業務モジュールの名前、無ければ「—」', () => {
        expect(moduleNameText('プロジェクト')).toBe('プロジェクト');
        expect(moduleNameText(null)).toBe('—');
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
        ).toEqual({ name: '版 3', period: '2026/10/01 から 終了日なし' });
        expect(
            currentVersionLines({
                number: 2,
                startDate: '2026-04-01',
                endDate: '2026-09-30',
            })?.period,
        ).toBe('2026/04/01 から 2026/09/30');
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

    it('申請対象の業務モジュールが無ければ「—」', () => {
        expect(flowHeadlineText(null, [version(1, true)])).toBe(
            '業務モジュール：—　現在の版は版 1 です。',
        );
    });
});

describe('stepKindWord', () => {
    it('申請・承認・横配置・縦配置は種類の語', () => {
        expect(stepKindWord('apply')).toBe('申請');
        expect(stepKindWord('approve')).toBe('承認');
        expect(stepKindWord('serial')).toBe('順次承認');
        expect(stepKindWord('parallel')).toBe('並列承認');
    });

    it('分岐・同期は無い(見出しは名前だけ)', () => {
        expect(stepKindWord('branch_start')).toBeNull();
        expect(stepKindWord('branch_end')).toBeNull();
        expect(stepKindWord('sync_start')).toBeNull();
        expect(stepKindWord('sync_end')).toBeNull();
    });
});
