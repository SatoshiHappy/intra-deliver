import { UserPickerField } from '@/components/Workflow/UserPickerField';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import type { WorkflowDesignation } from '@/types';

/**
 * 「選択中 {n} 人」の後ろの文(ルートの深さ/画面.md 6 節)。必要な数はサーバーの表 `decision.required` を引き、画面は式を持たない。
 */
function statusNoteOf(
    assigneeField: WorkflowDesignation,
    count: number,
): string {
    if (count === 0 && assigneeField.min === 0) {
        return '　このステップは省略されます。';
    }

    if (assigneeField.decision === null || count === 0) {
        return '';
    }

    // 表に無い人数(最大を超える)では文を出さない
    const required = assigneeField.decision.required[count - 1];

    return required !== undefined && count >= required
        ? `　完了の条件 ${count} 人中 ${required} 人が承認すると、合議は完了します。`
        : '';
}

/**
 * 承認のダイアログの指定の欄 1 つ(指定されるステップごと。ルートの深さ/画面.md 6 節)。選ぶ部分(選んだ人の並び・候補の検索)は人を選ぶ欄
 * {@see UserPickerField}(候補 = 処理対象者の候補の口。横配置は並びが承認の順)。見出しと状態の 1 行を足す。
 * 人数の上限を超えても選べ、超える分は送った時の 422 が正本。エラー(assignments.<id>)は欄の下。
 */
export function AssigneeField({
    caseId,
    assigneeField,
    picked,
    onChange,
    error,
}: {
    caseId: number;
    assigneeField: WorkflowDesignation;
    picked: PickedUser[];
    onChange: (picked: PickedUser[]) => void;
    error?: string;
}) {
    const id = `case-action-assignee-${assigneeField.nodeId}`;
    const headingId = `${id}-heading`;

    return (
        <section aria-labelledby={headingId} className="grid gap-2">
            <h3 id={headingId} className="text-sm font-semibold">
                {`指定するステップ　${assigneeField.kindLabel} ${assigneeField.name}`}
            </h3>
            <UserPickerField
                id={id}
                label="処理担当者"
                help={`${assigneeField.min} 人から ${assigneeField.max} 人まで選択できます。`}
                error={error}
                caseId={caseId}
                candidateNodeId={assigneeField.nodeId}
                picked={picked}
                isOrdered={assigneeField.kind === 'serial'}
                listLabel={`${assigneeField.name}の処理担当者`}
                candidateLabel={`${assigneeField.name}の処理担当者の候補`}
                max={Number.POSITIVE_INFINITY}
                onChange={onChange}
            />
            <p className="text-sm text-muted-foreground" aria-live="polite">
                {`選択中 ${picked.length} 人`}
                {statusNoteOf(assigneeField, picked.length)}
            </p>
        </section>
    );
}
