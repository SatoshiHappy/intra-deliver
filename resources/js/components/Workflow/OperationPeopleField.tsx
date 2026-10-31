import { FieldHelp } from '@/components/FormBlock';
import { PersonMenu } from '@/components/Workflow/PersonMenu';
import { EMPTY_VALUE } from '@/lib/recordValues';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowLinkedPerson } from '@/types';

/**
 * 人の札の並びを見せるだけの欄(申請操作のダイアログの「現在の処理担当者」「展開後の処理担当者」。選ぶ欄は UserPickerField)。札に「…」。
 * 人がいなければ「—」。help があればラベルの横に「?」。
 */
export function OperationPeopleField({
    id,
    label,
    help,
    people,
}: {
    id: string;
    label: string;
    help?: string;
    people: WorkflowLinkedPerson[];
}) {
    return (
        <div
            role="group"
            aria-labelledby={`${id}-label`}
            className="flex flex-col gap-2"
        >
            <div className="flex items-center gap-2">
                <span
                    id={`${id}-label`}
                    className="text-sm leading-none font-medium"
                >
                    {label}
                </span>
                {help !== undefined && (
                    <FieldHelp id={id} label={label}>
                        {help}
                    </FieldHelp>
                )}
            </div>
            {people.length === 0 ? (
                <p className="text-sm text-muted-foreground">{EMPTY_VALUE}</p>
            ) : (
                <ul aria-label={label} className="flex flex-wrap gap-2">
                    {people.map((person) => (
                        <li
                            key={person.id}
                            className="flex items-center gap-1 rounded-full border bg-muted/40 px-3 py-0.5 text-sm"
                        >
                            {personName(person)}
                            <PersonMenu person={person} />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
