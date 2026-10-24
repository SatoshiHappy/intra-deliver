import { ArrowDown, ArrowUp, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import { personName } from '@/lib/Workflow/workflowLabels';

/**
 * 選んだ人の札の並び(名前と所属・解除の印)。横配置(isSerial)は並びが承認の順なので、上へ・下への印を足す。
 */
export function AssigneePickedList({
    fieldName,
    picked,
    isSerial,
    onChange,
}: {
    fieldName: string;
    picked: PickedUser[];
    isSerial: boolean;
    onChange: (picked: PickedUser[]) => void;
}) {
    const move = (index: number, offset: -1 | 1) => {
        const next = [...picked];
        [next[index], next[index + offset]] = [
            next[index + offset],
            next[index],
        ];
        onChange(next);
    };

    return (
        <ul
            className="flex flex-wrap gap-2"
            aria-label={`${fieldName}の処理担当者`}
        >
            {picked.map((person, index) => (
                <li
                    key={person.id}
                    className="flex items-center gap-1 rounded-full border bg-muted/40 py-0.5 pr-1 pl-3 text-sm"
                >
                    <span>{personName(person)}</span>
                    {(person.mainDepartmentName ?? null) !== null && (
                        <span className="text-xs text-muted-foreground">
                            {person.mainDepartmentName}
                        </span>
                    )}
                    {isSerial && (
                        <>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                aria-label={`${personName(person)}を上へ`}
                                disabled={index === 0}
                                onClick={() => move(index, -1)}
                            >
                                <ArrowUp aria-hidden="true" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                aria-label={`${personName(person)}を下へ`}
                                disabled={index === picked.length - 1}
                                onClick={() => move(index, 1)}
                            >
                                <ArrowDown aria-hidden="true" />
                            </Button>
                        </>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-6"
                        aria-label={`${personName(person)}の選択を解除`}
                        onClick={() =>
                            onChange(
                                picked.filter(
                                    (other) => other.id !== person.id,
                                ),
                            )
                        }
                    >
                        <X aria-hidden="true" />
                    </Button>
                </li>
            ))}
        </ul>
    );
}
