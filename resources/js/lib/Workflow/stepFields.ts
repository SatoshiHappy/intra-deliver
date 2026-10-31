import { targetKindChoices } from '@/lib/Workflow/stepCardTexts';
import type {
    WorkflowDecisionRule,
    WorkflowNodeKind,
    WorkflowTargetKind,
} from '@/types';

/**
 * ステップの設定のカード 1・カード 4 に出す欄(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節のデシジョン表)。
 * 条件 = ステップの種類・指定の方法(入力の今の値)・可決の条件(入力の今の値)。
 */

/** カード 1 の欄(並び = 画面の上から) */
export type TargetField =
    /** 指定の方法(選べるのが 2 つ以上のときだけ) */
    | 'targetKind'
    /** 対象者 */
    | 'subjects'
    /** 基準にする人・組織・役職 */
    | 'relative'
    /** 選択する人 */
    | 'designator'
    /** 人数の最小・最大(カード 4 が無いときだけ) */
    | 'range';

/** カード 4 の欄(並び = 画面の上から) */
export type DecisionField = 'range' | 'decisionRule' | 'count' | 'ratio';

export type StepFields = { target: TargetField[]; decision: DecisionField[] };

/** 指定の方法ごとのカード 1 の欄(指定の方法の欄を除く) */
const TARGET_FIELDS: Record<WorkflowTargetKind, TargetField[]> = {
    fixed: ['subjects'],
    relative: ['relative'],
    designated: ['designator', 'range'],
};

/** 可決の条件ごとの、可決の条件の下の欄(全員 = 欄なし) */
const DECISION_VALUE_FIELDS: Record<WorkflowDecisionRule, DecisionField[]> = {
    all: [],
    count: ['count'],
    ratio: ['ratio'],
};

/**
 * カード 1・カード 4 に出す欄。カード 4 は縦配置だけで、人数の最小・最大はカード 4 があればカード 4 だけに出す。
 * 指定の方法・可決の条件が空(サーバーの値が無い)の行は起きない(DB の検査制約 workflow_route_nodes_kind_target_kind_check・
 * workflow_route_nodes_kind_decision_rule_check)ので、その欄を出さない。
 */
export function stepFieldsOf(
    kind: WorkflowNodeKind,
    targetKind: WorkflowTargetKind | '',
    decisionRule: WorkflowDecisionRule | '',
): StepFields {
    const hasDecisionCard = kind === 'parallel';
    const chooser: TargetField[] =
        targetKindChoices(kind).length > 1 ? ['targetKind'] : [];
    const byKind = targetKind === '' ? [] : TARGET_FIELDS[targetKind];
    const target = [
        ...chooser,
        ...byKind.filter((field) => !(hasDecisionCard && field === 'range')),
    ];

    if (!hasDecisionCard) {
        return { target, decision: [] };
    }

    return {
        target,
        decision: [
            'range',
            'decisionRule',
            ...(decisionRule === '' ? [] : DECISION_VALUE_FIELDS[decisionRule]),
        ],
    };
}

/** 欄ごとの、欄の下に出す誤りのキー */
const FIELD_ERROR_KEYS: Record<TargetField | DecisionField, string[]> = {
    targetKind: ['target_kind'],
    subjects: ['targets'],
    relative: ['relative_base', 'relative_scope', 'company_post_id'],
    designator: ['designator_node_code'],
    range: ['min_assignees', 'max_assignees'],
    decisionRule: ['decision_rule'],
    count: ['decision_value'],
    ratio: ['decision_value'],
};

/**
 * 出している欄が下に出す誤りのキー(行の誤り targets.<添字> は対象者の欄が出す)。
 */
export function fieldErrorKeys(fields: StepFields): string[] {
    return [...fields.target, ...fields.decision].flatMap(
        (field) => FIELD_ERROR_KEYS[field],
    );
}
