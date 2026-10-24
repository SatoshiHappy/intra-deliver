import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock } from '@/components/FormBlock';
import { Badge } from '@/components/ui/badge';
import { CaseActionAssigneeFields } from '@/components/Workflow/CaseActionAssigneeFields';
import { CaseActionContactField } from '@/components/Workflow/CaseActionContactField';
import { CaseActionNodeSelect } from '@/components/Workflow/CaseActionNodeSelect';
import { CaseActionCallout } from '@/components/Workflow/CaseActionNotices';
import { CaseActionPrincipalField } from '@/components/Workflow/CaseActionPrincipalField';
import { CaseActionSendBackFields } from '@/components/Workflow/CaseActionSendBackFields';
import { UserPickerField } from '@/components/Workflow/UserPickerField';
import type { CaseActionFormState } from '@/hooks/Workflow/use-case-action-form';
import {
    dialogNoteOf,
    nodeDefaults,
    nodeOf,
    operationWordOf,
    principalNoteOf,
    showsPrincipalField,
} from '@/lib/Workflow/caseActionForm';
import { OPERATION_TEXTS } from '@/lib/Workflow/caseActions';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import type { CaseActionTarget } from '@/lib/Workflow/caseActions';

type Props = CaseActionFormState & {
    target: CaseActionTarget;
    selfName: string;
};

const COMMENT_ID = 'case-action-comment';
const TRANSFER_ID = 'case-action-transfer';

/**
 * 欄の前半: 処理するステップの選択(ステップが 2 つ以上のとき)・説明の 1 行・処理担当者(選択肢が 2 つ以上のとき)。
 */
export function CaseActionLeadFields({
    target,
    form,
    errors,
    node,
    selfName,
}: Props) {
    const choices = node?.principals ?? [];
    const note = dialogNoteOf(target.operation, node);

    return (
        <>
            {target.nodes.length > 1 && (
                <CaseActionNodeSelect
                    label={
                        target.operation === 'pullBack'
                            ? '引戻すステップ'
                            : '処理するステップ'
                    }
                    nodes={target.nodes}
                    value={form.data.node_id}
                    onChange={(value) =>
                        form.setData((data) => ({
                            ...data,
                            node_id: value,
                            ...nodeDefaults(nodeOf(target.nodes, value)),
                        }))
                    }
                    error={errors.node_id}
                />
            )}
            {note !== null && <p className="text-sm">{note}</p>}
            {showsPrincipalField(node) && (
                <CaseActionPrincipalField
                    choices={choices}
                    value={form.data.principal}
                    note={principalNoteOf(
                        choices,
                        form.data.principal,
                        operationWordOf(target),
                        selfName,
                    )}
                    error={errors.principal_user_id}
                    onChange={(value) => form.setData('principal', value)}
                />
            )}
        </>
    );
}

/**
 * 変更後の処理担当者の欄(担当変更。ユーザー 1 人を検索して選ぶ)。
 */
function TransferField({
    caseId,
    picked,
    error,
    onChange,
}: {
    caseId: number;
    picked: PickedUser[];
    error: string | undefined;
    onChange: (users: PickedUser[]) => void;
}) {
    return (
        <UserPickerField
            id={TRANSFER_ID}
            label="変更後の処理担当者"
            labelAddon={<Badge variant="secondary">必須</Badge>}
            help="指定できるのはユーザー 1 人です。自分自身、申請基準日に無効な利用者、このステップのほかの承認者と同じ利用者は指定できません。"
            error={error}
            caseId={caseId}
            picked={picked}
            max={1}
            onChange={onChange}
        />
    );
}

/**
 * 欄の中盤: 指定(承認)・担当変更先(担当変更)・差戻し先(差戻し)・注意の囲み(保留・担当変更)。
 */
export function CaseActionMiddleFields({
    target,
    form,
    errors,
    node,
    assigneeFields,
}: Props) {
    const callout = OPERATION_TEXTS[target.operation].callout;

    return (
        <>
            <CaseActionAssigneeFields
                caseId={target.caseId}
                assigneeFields={assigneeFields}
                assignments={form.data.assignments}
                errors={errors}
                onChange={(nodeId, picked) =>
                    form.setData((data) => ({
                        ...data,
                        assignments: {
                            ...data.assignments,
                            [String(nodeId)]: picked,
                        },
                    }))
                }
            />
            {target.operation === 'transfer' && (
                <TransferField
                    caseId={target.caseId}
                    picked={form.data.to_user}
                    error={errors.to_user_id}
                    onChange={(users) => form.setData('to_user', users)}
                />
            )}
            {target.operation === 'sendBack' && (
                <CaseActionSendBackFields
                    targets={node?.sendBackTargets ?? []}
                    isRestricted={node?.sendBackRestricted === true}
                    value={form.data.send_back_node_id}
                    onChange={(value) =>
                        form.setData('send_back_node_id', value)
                    }
                    error={errors.send_back_node_id}
                />
            )}
            {callout !== null && <CaseActionCallout {...callout} />}
        </>
    );
}

/**
 * 欄の後半: コメントと連絡(添付の欄は後からコメントと連絡の間に入る)。
 */
export function CaseActionTailFields({
    target,
    form,
    errors,
}: Pick<Props, 'target' | 'form' | 'errors'>) {
    return (
        <>
            <FormBlock
                id={COMMENT_ID}
                label="コメント"
                help="任意の入力です。"
                error={errors.comment}
            >
                <CountedTextInput
                    id={COMMENT_ID}
                    isMultiline
                    value={form.data.comment}
                    max={target.commentMaxLength}
                    invalid={errors.comment !== undefined}
                    hasHelp
                    onChange={(event) =>
                        form.setData('comment', event.target.value)
                    }
                />
            </FormBlock>
            <CaseActionContactField
                users={form.data.contact_users}
                message={form.data.contact_message}
                errors={errors}
                onUsersChange={(users) => form.setData('contact_users', users)}
                onMessageChange={(message) =>
                    form.setData('contact_message', message)
                }
            />
        </>
    );
}
