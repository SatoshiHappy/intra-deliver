import { EMPTY_VALUE, formatDate } from '@/lib/recordValues';
import type {
    WorkflowCaseIndexProps,
    WorkflowCaseRow,
    WorkflowCaseTab,
    WorkflowDeadline,
} from '@/types';

/**
 * 案件一覧のタブ・列・セルの値(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節、列 = 認可と一覧.md の 2.2 節)。
 */

/** 表の列(タブごとに出す列の並びは COLUMNS) */
export type CaseListColumn =
    | 'select'
    | 'name'
    | 'applicant'
    | 'appliedAt'
    | 'currentStep'
    | 'principals'
    | 'elapsed'
    | 'due'
    | 'confirmSteps'
    | 'confirmOpenedAt'
    | 'confirmedSteps'
    | 'confirmedAt'
    | 'state'
    | 'actions';

/** タブ(並びの順)と、0 件のときの文 */
export const CASE_TABS: {
    tab: WorkflowCaseTab;
    label: string;
    empty: string;
}[] = [
    {
        tab: 'draft',
        label: '一時保存',
        empty: '一時保存した申請はありません',
    },
    {
        tab: 'pending',
        label: '未処理',
        empty: '処理を待っている申請はありません',
    },
    {
        tab: 'unconfirmed',
        label: '未確認',
        empty: '確認を待っている申請はありません',
    },
    {
        tab: 'processed',
        label: '処理済',
        empty: '処理した申請はありません',
    },
    {
        tab: 'confirmed',
        label: '確認済',
        empty: '確認した申請はありません',
    },
    {
        tab: 'referenced',
        label: '参照',
        empty: '参照できる申請はありません',
    },
];

/** 未完了・完了の切替を持つタブ */
export const SCOPED_TABS: WorkflowCaseTab[] = [
    'processed',
    'confirmed',
    'referenced',
];

/** 検索の語があるときの 0 件の文(タブによらない) */
const SEARCH_EMPTY_TEXT = '該当する申請はありません';

const COLUMNS: Record<WorkflowCaseTab, CaseListColumn[]> = {
    draft: ['name', 'applicant', 'state', 'actions'],
    pending: [
        'select',
        'name',
        'applicant',
        'currentStep',
        'principals',
        'elapsed',
        'due',
    ],
    unconfirmed: [
        'select',
        'name',
        'applicant',
        'currentStep',
        'confirmSteps',
        'confirmOpenedAt',
    ],
    confirmed: [
        'name',
        'applicant',
        'currentStep',
        'confirmedSteps',
        'confirmedAt',
    ],
    processed: [
        'name',
        'applicant',
        'appliedAt',
        'currentStep',
        'state',
        'actions',
    ],
    referenced: [
        'name',
        'applicant',
        'appliedAt',
        'currentStep',
        'state',
        'actions',
    ],
};

/**
 * タブの定義(読み取りが解釈したタブなので見つかる。見つからなければ既定の未処理)。
 */
export function caseTabOf(tab: WorkflowCaseTab) {
    return (
        CASE_TABS.find((definition) => definition.tab === tab) ?? CASE_TABS[1]
    );
}

/**
 * タブの表の列(左から)。
 */
export function columnsOf(tab: WorkflowCaseTab): CaseListColumn[] {
    return COLUMNS[tab];
}

/**
 * タブの件数(一時保存・未処理・未確認だけ。ほかは null = 出さない)。
 */
export function countOf(
    tab: WorkflowCaseTab,
    counts: WorkflowCaseIndexProps['counts'],
): number | null {
    switch (tab) {
        case 'draft':
            return counts.draft;
        case 'pending':
            return counts.pending;
        case 'unconfirmed':
            return counts.unconfirmed;
        default:
            return null;
    }
}

/**
 * 0 件のときの文(検索の語があればタブによらず「該当する申請はありません」)。q は読み取りが解釈した語(空 = 絞らない)。
 */
export function emptyTextOf(tab: WorkflowCaseTab, q: string): string {
    return q === '' ? caseTabOf(tab).empty : SEARCH_EMPTY_TEXT;
}

/**
 * 名前の並びの文(「、」で並べる。無ければ「—」)。
 */
export function namesText(names: string[]): string {
    return names.length === 0 ? EMPTY_VALUE : names.join('、');
}

/**
 * 期限のセルの値: 期限日(YYYY/MM/DD。無ければ null =「—」)と期限超過か。
 */
export function dueOf(deadline: WorkflowDeadline | null): {
    date: string | null;
    overdue: boolean;
} {
    if (deadline === null || deadline.dueOn === null) {
        return { date: null, overdue: false };
    }

    return { date: formatDate(deadline.dueOn), overdue: deadline.overdue };
}

/**
 * 経過日数(無ければ null =「—」)。
 */
export function elapsedDaysOf(
    deadline: WorkflowDeadline | null,
): number | null {
    return deadline?.elapsedDays ?? null;
}

/**
 * 申請名の 2 行目の番号の部分(一時保存は番号を持たず、フロー名だけ)。
 */
export function sublineNumberOf(row: WorkflowCaseRow): string | null {
    return row.state === 'draft' ? null : row.number;
}
