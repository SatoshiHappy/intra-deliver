import type { StepForm } from '@/lib/Workflow/stepForm';
import { fieldErrorKeys } from '@/lib/Workflow/stepFields';
import type { StepFields } from '@/lib/Workflow/stepFields';

/**
 * ステップの設定の誤りのキー(欄の名前 = 422 のキー。docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節):
 * 欄を変えたときに消すキーと、欄の下に出すキー。
 */

/** 欄ごとの誤りのキー(欄を変えたら、その欄の誤りを消す) */
const ERROR_KEYS: Record<keyof StepForm, string[]> = {
    targetKind: [
        'target_kind',
        'targets',
        'relative_base',
        'relative_scope',
        'company_post_id',
        'designator_node_code',
        'min_assignees',
        'max_assignees',
    ],
    rows: ['targets'],
    relativeBase: ['relative_base'],
    relativeScope: ['relative_scope'],
    companyPostId: ['company_post_id'],
    designatorCode: ['designator_node_code', 'min_assignees'],
    min: ['min_assignees'],
    max: ['min_assignees', 'max_assignees'],
    prevents: ['prevents_applicant_approval'],
    preventsAttachmentAdd: ['prevents_attachment_add'],
    sendBack: ['send_back_node_codes'],
    decisionRule: ['decision_rule', 'decision_value', 'min_assignees'],
    countValue: ['decision_value'],
    ratioValue: ['decision_value'],
    deadline: ['deadline_days'],
    confirmers: ['confirmers'],
};

/**
 * 欄を変えたときに消す誤りのキー。
 */
export function errorKeysOf(field: keyof StepForm): string[] {
    return ERROR_KEYS[field];
}

/** カードの欄の下に出す誤りのキー(カードはノードが設定を持てば出る) */
const CARD_KEYS = [
    'prevents_applicant_approval',
    'send_back_node_codes',
    'prevents_attachment_add',
    'deadline_days',
    'confirmers',
];

/**
 * 欄の下に出すキー(誤りのうち、欄の無いキーだけを先頭に出すための照合)。カード 1・カード 4 は出している欄のキーだけ
 * (出していない欄の誤りは先頭に出す)。行の誤り targets.<添字> は対象者の欄を出しているときだけ。確認する人の行の誤り
 * confirmers.<添字> はカード「確認する人」の欄の下(カードを持つノードにだけ返る)。
 */
export function shownErrorKeys(
    errorKeys: string[],
    fields: StepFields,
): string[] {
    const hasSubjects = fields.target.includes('subjects');

    return [
        ...fieldErrorKeys(fields),
        ...CARD_KEYS,
        ...(hasSubjects
            ? errorKeys.filter((key) => key.startsWith('targets.'))
            : []),
        ...errorKeys.filter((key) => key.startsWith('confirmers.')),
    ];
}
