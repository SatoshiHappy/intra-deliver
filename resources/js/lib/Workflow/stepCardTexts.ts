import type {
    WorkflowDecisionRule,
    WorkflowNodeKind,
    WorkflowRelativeBase,
    WorkflowRelativeScope,
    WorkflowTargetKind,
} from '@/types';

/**
 * ステップの設定のカードの文(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節、
 * 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 9.2 節・9.3 節)。見出しの下の 1 行は指定の種類で変わる。
 */

export const TARGET_KIND_LABELS: Record<WorkflowTargetKind, string> = {
    fixed: '対象者で指定',
    relative: '基準にする人で指定',
    designated: '処理の時に指定',
};

export const BASE_LABELS: Record<WorkflowRelativeBase, string> = {
    applicant: '申請者',
    previous: '前の承認者',
};

export const SCOPE_LABELS: Record<WorkflowRelativeScope, string> = {
    own: '所属組織',
    parent: '上位組織',
    ancestors: '上位組織全て',
};

export const DECISION_LABELS: Record<WorkflowDecisionRule, string> = {
    all: '全員',
    count: '人数',
    ratio: '割合',
};

/** 入力欄の「?」の説明(モックで入力欄の下に出ている文と、正本が「?」に置く文) */
export const HELP = {
    base: '前の承認者は、直前のステップを処理した人です。前処理者とも呼びます。',
    scope: '基準にする人から見て、対象とする組織を選択します。',
    post: '申請基準日時点の所属と役職で決定されます。',
    sendBack:
        'チェックしたステップにだけ差戻しできます。1 つもチェックしない場合、このステップでは差戻しできません。',
    ratio: '割合を選択したときに使用します。',
};

export const SEND_BACK_LINE = '差戻しの際に指定できる差戻し先を設定します。';
export const DECISION_LINE =
    '合議の人数と、次へ進むために必要な承認の条件を設定します。';

/**
 * 選べる指定の種類(申請は対象者で指定だけ、横配置・縦配置は処理の時に指定だけ。分岐・同期は設定を持たない)。
 */
export function targetKindChoices(
    kind: WorkflowNodeKind,
): WorkflowTargetKind[] {
    switch (kind) {
        case 'apply':
            return ['fixed'];
        case 'approve':
            return ['fixed', 'relative', 'designated'];
        case 'serial':
        case 'parallel':
            return ['designated'];
        default:
            return [];
    }
}

/**
 * カード 1 の見出し(申請は「誰が申請できるか」、ほかは「誰が承認するか」)。
 */
export function targetHeading(kind: WorkflowNodeKind): string {
    return kind === 'apply' ? '誰が申請できるか' : '誰が承認するか';
}

/**
 * カード 1 の見出しの下の 1 行(指定の種類で変える)。
 */
export function targetLine(
    kind: WorkflowNodeKind,
    targetKind: WorkflowTargetKind | '',
): string | null {
    if (targetKind === 'fixed') {
        return kind === 'apply'
            ? '申請できる人を、ユーザー、組織、役職、ロールで指定します。'
            : '承認者を、ユーザー、組織、役職、ロールで指定します。';
    }

    if (targetKind === 'relative') {
        return '承認者を、基準にする人、組織、役職で指定します。';
    }

    if (targetKind === 'designated') {
        return kind === 'parallel'
            ? '合議に参加する人を、誰がいつ選択するかを設定します。'
            : '承認者を、誰がいつ選択するかを設定します。';
    }

    return null;
}

/**
 * カード 2 の見出しの下の 1 行(縦配置は合議の承認者への選択)。
 */
export function preventsLine(kind: WorkflowNodeKind): string {
    return kind === 'parallel'
        ? 'オンにすると、申請者本人を合議の承認者に選択できません。'
        : 'オンにすると、申請者本人はこのステップで自分の申請を承認できません。';
}
