import { EMPTY_VALUE, formatDate } from '@/lib/recordValues';
import { personName } from '@/lib/Workflow/workflowLabels';
import type {
    StatusTone,
    WorkflowActorView,
    WorkflowCaseHistory,
    WorkflowCaseState,
    WorkflowConfirmationProgress,
    WorkflowDeadline,
    WorkflowPerson,
} from '@/types';

/**
 * 申請の詳細の表示の値(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節、設計/ワークフロー/案件の運用/画面/README.md の 8.2 節)。
 */

/**
 * 処理実行者の表示(実行者が処理者と違えば「{実行者}({処理者}さんの代理)」、同じなら処理者の名前)。
 */
export function actorViewText(view: WorkflowActorView): string {
    return view.executor === null
        ? personName(view.actor)
        : `${personName(view.executor)}(${personName(view.actor)}さんの代理)`;
}

/**
 * 名前の並び(「、」でつなぐ)。
 */
function namesOf(people: WorkflowPerson[]): string {
    return people.map(personName).join('、');
}

/**
 * 履歴の種別の札の後に添える 1 行(コメントの下)。担当変更「担当変更: {振替先}」、処理担当者の変更・再展開「処理担当者: {名前}」、
 * 戻る「戻り先: {ステップ名}」、指定の変更「指定: {ステップ名} = {名前}」(0 人は「なし」)。ほかの種別は null。
 */
export function historyNoteOf(
    history: Pick<
        WorkflowCaseHistory,
        'action' | 'nodeName' | 'users' | 'moveTargetName'
    >,
): string | null {
    switch (history.action) {
        case 'transfer':
            return `担当変更: ${namesOf(history.users)}`;
        case 'retarget':
        case 'reexpand':
            return `処理担当者: ${namesOf(history.users)}`;
        case 'move_back':
            return history.moveTargetName === null
                ? null
                : `戻り先: ${history.moveTargetName}`;
        case 'reassign':
            return `指定: ${history.nodeName} = ${history.users.length === 0 ? 'なし' : namesOf(history.users)}`;
        default:
            return null;
    }
}

/**
 * 承認の行の指定の 1 行(「指定: {ステップ名} = {名前}」。0 人を指定したときは「省略」)。
 */
export function assignmentNoteOf(
    assignment: WorkflowCaseHistory['assignments'][number],
): string {
    return `指定: ${assignment.nodeName} = ${assignment.people.length === 0 ? '省略' : namesOf(assignment.people)}`;
}

/**
 * 基本情報の到達日(最も古い現在のステップの到達した日。YYYY/MM/DD。無ければ null)。
 */
export function reachedDateOf(
    deadline: WorkflowDeadline | null,
): string | null {
    return deadline === null || deadline.reachedOn === null
        ? null
        : formatDate(deadline.reachedOn);
}

/**
 * 処理担当者の列の文(現在のステップの処理担当者を「、」で並べ、自分に「(自分)」。いなければ null)。
 */
export function targetsCellText(
    targets: (WorkflowPerson & { isMe: boolean })[],
): string | null {
    return targets.length === 0
        ? null
        : targets
              .map(
                  (person) =>
                      `${personName(person)}${person.isMe ? '(自分)' : ''}`,
              )
              .join('、');
}

/** 確認の状態の札(語・色。色の無いもの = 省略・確認の行なしは札にせず文字だけ。docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.5 節) */
export const CONFIRMATION_BADGES: Record<
    WorkflowConfirmationProgress,
    { label: string; tone: StatusTone | null }
> = {
    unconfirmed: { label: '未確認', tone: 'pending' },
    confirmed: { label: '確認済', tone: 'settled' },
    waiting: { label: '承認待ち', tone: 'pending' },
    reapply: { label: '再申請待ち', tone: 'returned' },
    skipped: { label: '省略', tone: null },
    none: { label: EMPTY_VALUE, tone: null },
};

/**
 * 確認の面の 1 行(自分が確認する人かで 2 つの形)。
 */
export function confirmationNoteOf(isConfirmer: boolean): string {
    return isConfirmer
        ? '確認は処理に影響しません。何度でも確認でき、確認した日時が残ります。'
        : '確認は処理に影響しません。確認する人は、ステップが処理済みになると確認できます。';
}

/** 基本情報の進み具合の項目(画面の並びの順) */
export type ProgressItemKey =
    | 'currentStep'
    | 'completedOn'
    | 'due'
    | 'reachedOn'
    | 'elapsedDays';

/**
 * 基本情報の進み具合の項目の並び(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節、設計/ワークフロー/画面.md の 5 節)。
 * 現在のステップはいつも出す(完了のときの値は「—」)。完了は完了日、未完了は期限・到達日・経過日数が続く。
 */
export function progressItemKeysOf(
    state: WorkflowCaseState,
): ProgressItemKey[] {
    return state === 'completed'
        ? ['currentStep', 'completedOn']
        : ['currentStep', 'due', 'reachedOn', 'elapsedDays'];
}
