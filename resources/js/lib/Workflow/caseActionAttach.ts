import { chosenPrincipalOf } from '@/lib/Workflow/caseActionForm';
import type {
    CaseActionNode,
    CaseActionTarget,
    CaseOperation,
} from '@/lib/Workflow/caseActions';
import type {
    WorkflowAttachmentLimits,
    WorkflowCaseIndexProps,
    WorkflowCaseShowProps,
} from '@/types';

/**
 * 処理のダイアログの添付の欄の出し方(docs の 設計/ワークフロー/申請の周辺/画面.md の 3 節、添付の可否の置き場 = 設計/ワークフロー/申請の周辺/添付/README.md の 5 節)。
 * ダイアログの対象は開いた時の写しだが、添付の可否だけはページの今の props から読む(誤りの戻りで可否が変わっても追えるように)。
 */

/** 選んだ入口(操作・案件・ステップ・処理者。取止めはステップなし、処理者を選べない操作は null) */
export type AttachChoice = {
    operation: CaseOperation;
    caseId: number;
    nodeId: number | null;
    principalId: number | null;
};

/** ページの今の props から作る、添付の読み口(上限と、選んだ入口の可否) */
export type PageAttach = {
    limits: WorkflowAttachmentLimits | null;
    canAttach: (choice: AttachChoice) => boolean;
};

/**
 * 申請の詳細の読み口(引戻し = 引戻せるステップ、取止め = canAttachOnWithdraw、ほか = nodeActions の操作 × 処理者の選択肢)。
 */
export function showAttachOf(
    show: Pick<
        WorkflowCaseShowProps,
        | 'nodeActions'
        | 'pullBackNodes'
        | 'canAttachOnWithdraw'
        | 'attachmentLimits'
    >,
): PageAttach {
    return {
        limits: show.attachmentLimits,
        canAttach: ({ operation, nodeId, principalId }) => {
            if (operation === 'withdraw') {
                return show.canAttachOnWithdraw;
            }

            if (operation === 'pullBack') {
                return show.pullBackNodes.some(
                    (node) => node.id === nodeId && node.canAttach,
                );
            }

            const row = show.nodeActions.find((item) => item.nodeId === nodeId);

            return (row?.principals[operation] ?? []).some(
                (choice) => choice.id === principalId && choice.canAttach,
            );
        },
    };
}

/**
 * 申請一覧の読み口(行は引戻しと取止めだけを開く。ほかの操作は一覧の行に入口が無いので偽。行が一覧から外れたら偽)。
 */
export function indexAttachOf(
    index: Pick<WorkflowCaseIndexProps, 'cases' | 'attachmentLimits'>,
): PageAttach {
    return {
        limits: index.attachmentLimits,
        canAttach: ({ operation, caseId, nodeId }) => {
            const row = index.cases.data.find((item) => item.id === caseId);

            if (row === undefined) {
                return false;
            }

            if (operation === 'withdraw') {
                return row.canAttachOnWithdraw;
            }

            return (
                operation === 'pullBack' &&
                row.pullBackNodes.some(
                    (node) => node.id === nodeId && node.canAttach,
                )
            );
        },
    };
}

/**
 * 選んでいる入口(操作 × ステップ × 処理者)の添付の欄を出して送るか。上限が無い(どの入口も偽)ときは出さない。
 */
export function attachableOf(
    attach: PageAttach,
    target: CaseActionTarget,
    node: CaseActionNode | undefined,
    selected: string,
): boolean {
    if (attach.limits === null) {
        return false;
    }

    const principal = chosenPrincipalOf(node?.principals ?? [], selected);

    return attach.canAttach({
        operation: target.operation,
        caseId: target.caseId,
        nodeId: node?.id ?? null,
        principalId: principal?.id ?? null,
    });
}
