import { formatDate } from '@/lib/recordValues';
import type {
    WorkflowFlowDefinitionTab,
    WorkflowFlowVersionPeriod,
    WorkflowFlowVersionRow,
    WorkflowNodeKind,
} from '@/types';

/**
 * 定義の管理の画面の文(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 2 節・3 節)。
 */

/** 終了日の無い版の終了日の語 */
export const NO_END_DATE = '終了日なし';

/** 詳細のタブ(リンクの並びの順) */
export const FLOW_TABS: { tab: WorkflowFlowDefinitionTab; label: string }[] = [
    { tab: 'diagram', label: 'フロー図' },
    { tab: 'versions', label: '版' },
    { tab: 'settings', label: '設定' },
];

/**
 * 版の名前。
 */
export function versionLabel(number: number): string {
    return `版 ${number}`;
}

/**
 * フロー図のタブの版の選択の選択肢(「版 3」と全角の空白と「2026/10/01 から」。日付は YYYY/MM/DD = アプリのほかの画面と同じ)。
 */
export function versionOptionLabel(
    version: Pick<WorkflowFlowVersionRow, 'number' | 'startDate'>,
): string {
    return `${versionLabel(version.number)}　${formatDate(version.startDate)} から`;
}

/**
 * 版の期間(「2026/10/01 から 終了日なし」)。endDate は表示の終了日。
 */
function periodText(startDate: string, endDate: string | null): string {
    return `${formatDate(startDate)} から ${endDate === null ? NO_END_DATE : formatDate(endDate)}`;
}

/**
 * 一覧の「現在の版」の 2 行(今日の版が無ければ null)。
 */
export function currentVersionLines(
    version: WorkflowFlowVersionPeriod | null,
): { name: string; period: string } | null {
    return version === null
        ? null
        : {
              name: versionLabel(version.number),
              period: periodText(version.startDate, version.endDate),
          };
}

/**
 * 申請対象の業務モジュールの名前(一覧の列と詳細の見出しの下の 1 行。申請対象なしは「—」)。
 */
export function moduleNameText(moduleName: string | null): string {
    return moduleName ?? '—';
}

/**
 * 一覧の参照者の欄(無ければ「指定なし」)。
 */
export function referrerSummaryText(summary: string | null): string {
    return summary ?? '指定なし';
}

/**
 * 版の状態の語。
 */
export function versionStateLabel(isDisabled: boolean): string {
    return isDisabled ? '無効' : '有効';
}

/**
 * 詳細の見出しの下の 1 行(業務モジュール = 申請対象の業務モジュール。今日の版が無ければ「今日に有効な版はありません。」)。
 */
export function flowHeadlineText(
    moduleName: string | null,
    versions: WorkflowFlowVersionRow[],
): string {
    const current = versions.find((version) => version.isCurrent);
    const tail =
        current === undefined
            ? '今日に有効な版はありません。'
            : `現在の版は${versionLabel(current.number)} です。`;

    return `業務モジュール：${moduleNameText(moduleName)}　${tail}`;
}

/** ステップの種類の語(分岐・同期は設定を持たないので種類の語を出さない) */
const STEP_KIND_WORDS: Partial<Record<WorkflowNodeKind, string>> = {
    apply: '申請',
    approve: '承認',
    serial: '順次承認',
    parallel: '並列承認',
};

/**
 * 図で選んだステップの見出しの横の種類のチップの語(分岐・同期は種類の語が無く、見出しは名前だけ)。
 */
export function stepKindWord(kind: WorkflowNodeKind): string | null {
    return STEP_KIND_WORDS[kind] ?? null;
}
