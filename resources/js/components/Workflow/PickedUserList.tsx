import { ArrowDown, ArrowUp, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { PersonMenu } from '@/components/Workflow/PersonMenu';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import { personName } from '@/lib/Workflow/workflowLabels';

type Props = {
    /** 並びの読み上げの名前 */
    label: string;
    picked: PickedUser[];
    /** 並びが処理の順(横配置の指定など)。上へ・下への印を足す */
    isOrdered?: boolean;
    onChange: (picked: PickedUser[]) => void;
};

/**
 * 札の中の小さい印のボタン(読み上げの名前を付ける)。
 */
function IconButton({
    label,
    isDisabled = false,
    onClick,
    children,
}: {
    label: string;
    isDisabled?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-6"
            aria-label={label}
            disabled={isDisabled}
            onClick={onClick}
        >
            {children}
        </Button>
    );
}

/**
 * 上へ・下への印(並びが処理の順のときだけ)。先頭は上へ、末尾は下へを押せない。
 */
function OrderButtons({
    name,
    index,
    count,
    onMove,
}: {
    name: string;
    index: number;
    count: number;
    onMove: (offset: -1 | 1) => void;
}) {
    return (
        <>
            <IconButton
                label={`${name}を上へ`}
                isDisabled={index === 0}
                onClick={() => onMove(-1)}
            >
                <ArrowUp aria-hidden="true" />
            </IconButton>
            <IconButton
                label={`${name}を下へ`}
                isDisabled={index === count - 1}
                onClick={() => onMove(1)}
            >
                <ArrowDown aria-hidden="true" />
            </IconButton>
        </>
    );
}

/**
 * 選んだ人の札 1 つ(名前・所属・「…」・上へ下へ(並びが処理の順のとき)・外す印)。
 */
function PickedUserRow({
    person,
    index,
    picked,
    isOrdered,
    onChange,
}: Omit<Props, 'label'> & { person: PickedUser; index: number }) {
    const move = (offset: -1 | 1) => {
        const next = [...picked];
        [next[index], next[index + offset]] = [
            next[index + offset],
            next[index],
        ];
        onChange(next);
    };
    const name = personName(person);

    return (
        <li className="flex items-center gap-1 rounded-full border bg-muted/40 py-0.5 pr-1 pl-3 text-sm">
            <span>{name}</span>
            {(person.mainDepartmentName ?? null) !== null && (
                <span className="text-xs text-muted-foreground">
                    {person.mainDepartmentName}
                </span>
            )}
            <PersonMenu person={person} />
            {isOrdered === true && (
                <OrderButtons
                    name={name}
                    index={index}
                    count={picked.length}
                    onMove={move}
                />
            )}
            <IconButton
                label={`${name}の選択を解除`}
                onClick={() =>
                    onChange(picked.filter((other) => other.id !== person.id))
                }
            >
                <X aria-hidden="true" />
            </IconButton>
        </li>
    );
}

/**
 * 選んだ人の札の並び(誰も選んでいなければ何も描かない)。人を選ぶ欄 {@see UserPickerField} が使う。
 */
export function PickedUserList({ label, ...rest }: Props) {
    if (rest.picked.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-wrap gap-2" aria-label={label}>
            {rest.picked.map((person, index) => (
                <PickedUserRow
                    key={person.id}
                    person={person}
                    index={index}
                    {...rest}
                />
            ))}
        </ul>
    );
}
