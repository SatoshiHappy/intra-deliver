import { AssigneeField } from '@/components/Workflow/AssigneeField';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import type { WorkflowDesignation } from '@/types';

/**
 * 承認のダイアログの指定の欄の列(指定されるステップごとに 1 つ)と、その上に出す指定全体のエラー。
 * assignments = 指定されるステップの id → 選んだ人。エラーのキーは assignments(全体)と assignments.<id>(欄ごと)。
 */
export function CaseActionAssigneeFields({
    caseId,
    assigneeFields,
    assignments,
    errors,
    onChange,
}: {
    caseId: number;
    assigneeFields: WorkflowDesignation[];
    assignments: Record<string, PickedUser[]>;
    errors: Record<string, string | undefined>;
    onChange: (nodeId: number, picked: PickedUser[]) => void;
}) {
    return (
        <>
            {assigneeFields.length > 0 && errors.assignments !== undefined && (
                <p role="alert" className="text-sm text-destructive">
                    {errors.assignments}
                </p>
            )}
            {assigneeFields.map((assigneeField) => (
                <AssigneeField
                    key={assigneeField.nodeId}
                    caseId={caseId}
                    assigneeField={assigneeField}
                    picked={assignments[String(assigneeField.nodeId)] ?? []}
                    onChange={(picked) =>
                        onChange(assigneeField.nodeId, picked)
                    }
                    error={errors[`assignments.${assigneeField.nodeId}`]}
                />
            ))}
        </>
    );
}
