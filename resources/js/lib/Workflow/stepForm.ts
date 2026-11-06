import { draftsOf, rowsPayloadOf } from '@/lib/Workflow/subjectRows';
import type {
    SubjectRowDraft,
    SubjectRowPayload,
} from '@/lib/Workflow/subjectRows';
import type {
    WorkflowDecisionRule,
    WorkflowRelativeBase,
    WorkflowRelativeScope,
    WorkflowStepSettings,
    WorkflowStepTarget,
    WorkflowTargetKind,
} from '@/types';

/**
 * ステップの設定の入力と、送る値(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 5.1 節・
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節。処理期限と確認する人 =
 * 設計/ワークフロー/案件の運用/定義の管理.md の 2 節)。1 つのステップの全カードを 1 回で送る。
 * どの欄も必ず送る(送らない欄はサーバーが 422 にする)。指定の種類・ステップの種類で使わない欄は空(null)で送る(5.1 の「禁止」の欄)。
 */

/** 入力。数の欄は打った文字のまま持つ(範囲の誤りはサーバーが欄の下に返す) */
export type StepForm = {
    targetKind: WorkflowTargetKind | '';
    rows: SubjectRowDraft[];
    relativeBase: WorkflowRelativeBase | '';
    relativeScope: WorkflowRelativeScope | '';
    companyPostId: string;
    designatorCode: string;
    min: string;
    max: string;
    prevents: boolean;
    preventsAttachmentAdd: boolean;
    sendBack: string[];
    decisionRule: WorkflowDecisionRule | '';
    countValue: string;
    ratioValue: string;
    deadline: string;
    confirmers: SubjectRowDraft[];
};

/** 送る値(欄の名前は 422 のキーと同じ) */
type StepPayload = {
    target_kind: WorkflowTargetKind | null;
    targets: SubjectRowPayload[] | null;
    relative_base: WorkflowRelativeBase | null;
    relative_scope: WorkflowRelativeScope | null;
    company_post_id: number | null;
    designator_node_code: string | null;
    min_assignees: number | string | null;
    max_assignees: number | string | null;
    prevents_applicant_approval: boolean | null;
    prevents_attachment_add: boolean | null;
    send_back_node_codes: string[] | null;
    decision_rule: WorkflowDecisionRule | null;
    decision_value: number | string | null;
    deadline_days: number | string | null;
    confirmers: number[] | null;
};

/** 指定の種類ごとの値を入れる前の入力(使わない欄の初期値) */
const EMPTY: StepForm = {
    targetKind: '',
    rows: [],
    relativeBase: '',
    relativeScope: '',
    companyPostId: '',
    designatorCode: '',
    min: '',
    max: '',
    prevents: false,
    preventsAttachmentAdd: false,
    sendBack: [],
    decisionRule: '',
    countValue: '',
    ratioValue: '',
    deadline: '',
    confirmers: [],
};

function targetValues(target: WorkflowStepTarget | null): Partial<StepForm> {
    if (target === null) {
        return {};
    }

    switch (target.kind) {
        case 'fixed':
            return { targetKind: 'fixed', rows: draftsOf(target.rows) };
        case 'relative':
            return {
                targetKind: 'relative',
                relativeBase: target.base,
                relativeScope: target.scope,
                companyPostId:
                    target.companyPostId === null
                        ? ''
                        : String(target.companyPostId),
            };
        case 'designated':
            return {
                targetKind: 'designated',
                designatorCode: target.designatorCode,
                min: String(target.min),
                max: String(target.max),
            };
    }
}

/**
 * サーバーのステップの設定から入力の初期値。
 */
export function formOf(step: WorkflowStepSettings): StepForm {
    const decision = step.decision;

    return {
        ...EMPTY,
        ...targetValues(step.target),
        prevents: step.preventsApplicantApproval ?? false,
        preventsAttachmentAdd: step.preventsAttachmentAdd ?? false,
        sendBack: step.sendBack?.selected ?? [],
        decisionRule: decision?.rule ?? '',
        countValue:
            decision?.rule === 'count' ? String(decision.value ?? '') : '',
        ratioValue:
            decision?.rule === 'ratio' ? String(decision.value ?? '') : '',
        deadline: String(step.deadline?.days ?? ''),
        confirmers: draftsOf(step.confirmers ?? []),
    };
}

/** 整数の形(符号つき) */
const INTEGER_PATTERN = /^-?\d+$/;

/**
 * 数の欄の送る値(空は null、整数の形は数、ほかは文字のまま = サーバーの「整数」の誤りを欄の下に出す)。
 */
function numberOf(text: string): number | string | null {
    const trimmed = text.trim();

    if (trimmed === '') {
        return null;
    }

    return INTEGER_PATTERN.test(trimmed) ? Number(trimmed) : trimmed;
}

function decisionPayload(
    form: StepForm,
): Pick<StepPayload, 'decision_rule' | 'decision_value'> {
    const rule = form.decisionRule === '' ? null : form.decisionRule;
    const text = { count: form.countValue, ratio: form.ratioValue };

    return {
        decision_rule: rule,
        decision_value:
            rule === 'count' || rule === 'ratio' ? numberOf(text[rule]) : null,
    };
}

/**
 * 送る値。ステップに持たない設定(step の null)と、指定の種類で使わない欄は null で送る。
 */
export function payloadOf(
    form: StepForm,
    step: WorkflowStepSettings,
): StepPayload {
    const kind = form.targetKind === '' ? null : form.targetKind;
    const isRelative = kind === 'relative';
    const isDesignated = kind === 'designated';
    const hasDecision = step.decision !== null;

    return {
        target_kind: kind,
        targets: kind === 'fixed' ? rowsPayloadOf(form.rows) : null,
        relative_base:
            isRelative && form.relativeBase !== '' ? form.relativeBase : null,
        relative_scope:
            isRelative && form.relativeScope !== '' ? form.relativeScope : null,
        company_post_id:
            isRelative && form.companyPostId !== ''
                ? Number(form.companyPostId)
                : null,
        designator_node_code:
            isDesignated && form.designatorCode !== ''
                ? form.designatorCode
                : null,
        min_assignees: isDesignated ? numberOf(form.min) : null,
        max_assignees: isDesignated ? numberOf(form.max) : null,
        prevents_applicant_approval:
            step.preventsApplicantApproval === null ? null : form.prevents,
        prevents_attachment_add:
            step.preventsAttachmentAdd === null
                ? null
                : form.preventsAttachmentAdd,
        send_back_node_codes: step.sendBack === null ? null : form.sendBack,
        ...(hasDecision
            ? decisionPayload(form)
            : { decision_rule: null, decision_value: null }),
        deadline_days: step.deadline === null ? null : numberOf(form.deadline),
        confirmers:
            step.confirmers === null
                ? null
                : form.confirmers.map((row) => row.id),
    };
}

/**
 * サーバーのステップの設定の識別(替わったら入力を作り直す。保存の後の取り直し・別のステップ・別の版)。
 */
export function stepSignature(step: WorkflowStepSettings): string {
    return JSON.stringify(step);
}

/** ステップの設定のカード(出す並び) */
export type NodeCard =
    | 'target'
    | 'prevents'
    | 'sendBack'
    | 'decision'
    | 'deadline'
    | 'confirmers'
    | 'attachment';

/**
 * ノードが持つ設定のカードを、出す並びで返す(番号は並びの順に 1 から振る。出す条件と番号の 1 か所。
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節、設計/ワークフロー/申請の周辺/画面.md の 4 節、
 * 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節)。カードは、ステップがその設定を持てば(null でなければ)出す。
 */
export function cardOrderOf(node: WorkflowStepSettings): NodeCard[] {
    const cards: [NodeCard, boolean][] = [
        ['target', node.target !== null],
        ['prevents', node.preventsApplicantApproval !== null],
        ['sendBack', node.sendBack !== null],
        ['decision', node.decision !== null],
        ['deadline', node.deadline !== null],
        ['confirmers', node.confirmers !== null],
        ['attachment', node.preventsAttachmentAdd !== null],
    ];

    return cards.filter(([, shown]) => shown).map(([card]) => card);
}
