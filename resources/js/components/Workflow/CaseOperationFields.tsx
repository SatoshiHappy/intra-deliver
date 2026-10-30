import { Badge } from '@/components/ui/badge';
import { CaseActionCallout } from '@/components/Workflow/CaseActionNotices';
import { OperationPeopleField } from '@/components/Workflow/OperationPeopleField';
import { UserPickerField } from '@/components/Workflow/UserPickerField';
import type { OperationFormState } from '@/hooks/Workflow/use-operation-form';
import {
    assignHelpOf,
    isAssignRequired,
    reexpandCalloutOf,
    reexpandHelpOf,
    retargetCalloutOf,
    retargetHelpOf,
} from '@/lib/Workflow/caseOperationTexts';
import type {
    WorkflowCaseAssignNode,
    WorkflowCaseReexpandNode,
    WorkflowCaseRetargetNode,
} from '@/types';

const TARGETS_ID = 'case-operation-targets';
const NEW_TARGETS_ID = 'case-operation-new-targets';
const EXPANDED_ID = 'case-operation-expanded';
const CURRENT_ASSIGN_ID = 'case-operation-current-assign';
const NEW_ASSIGN_ID = 'case-operation-new-assign';

type Props = Pick<OperationFormState, 'form' | 'errors'>;

/**
 * 処理担当者を変更するダイアログの欄(現在の処理担当者・変更後の処理担当者・注意の囲み)。人の候補は案件の申請基準日で引く。
 */
export function RetargetFields({
    caseId,
    node,
    form,
    errors,
}: Props & { caseId: number; node: WorkflowCaseRetargetNode | undefined }) {
    const callout = retargetCalloutOf(node?.onHold === true);

    return (
        <>
            <OperationPeopleField
                id={TARGETS_ID}
                label="現在の処理担当者"
                people={node?.targets ?? []}
            />
            <UserPickerField
                id={NEW_TARGETS_ID}
                label="変更後の処理担当者"
                labelAddon={<Badge variant="secondary">必須</Badge>}
                help={retargetHelpOf(node?.single === true)}
                error={errors.user_ids}
                caseId={caseId}
                picked={form.data.users}
                max={node?.max ?? 1}
                onChange={(users) => form.setData('users', users)}
            />
            <CaseActionCallout {...callout} />
        </>
    );
}

/**
 * 再展開するダイアログの欄(説明の 1 行・現在の処理担当者・展開後の処理担当者・注意の囲み)。
 */
export function ReexpandFields({
    node,
}: {
    node: WorkflowCaseReexpandNode | undefined;
}) {
    const callout = reexpandCalloutOf(node?.onHold === true);

    return (
        <>
            <p className="text-sm">
                フロー定義の指定で、現在のステップの処理担当者を展開し直します。
            </p>
            <OperationPeopleField
                id={TARGETS_ID}
                label="現在の処理担当者"
                people={node?.targets ?? []}
            />
            <OperationPeopleField
                id={EXPANDED_ID}
                label="展開後の処理担当者"
                help={reexpandHelpOf(node)}
                people={node?.expanded ?? []}
            />
            <CaseActionCallout {...callout} />
        </>
    );
}

/**
 * 指定を変更するダイアログの欄(今の指定・変更後の指定)。変更後の人は今の指定から始め、横配置は並びが処理の順(上へ・下へ)。
 * 人数は定義の最小〜最大(超える分は送った時の 422 が正本)。人の候補は案件の申請基準日で引く。
 */
export function AssignFields({
    caseId,
    node,
    form,
    errors,
}: Props & { caseId: number; node: WorkflowCaseAssignNode | undefined }) {
    return (
        <>
            <OperationPeopleField
                id={CURRENT_ASSIGN_ID}
                label="今の指定"
                people={node?.assigned ?? []}
            />
            <UserPickerField
                id={NEW_ASSIGN_ID}
                label="変更後の指定"
                labelAddon={
                    isAssignRequired(node) ? (
                        <Badge variant="secondary">必須</Badge>
                    ) : undefined
                }
                help={assignHelpOf(node)}
                error={errors.user_ids}
                caseId={caseId}
                picked={form.data.users}
                isOrdered={node?.ordered === true}
                max={node?.max ?? 1}
                onChange={(users) => form.setData('users', users)}
            />
        </>
    );
}
