import { FormBlock, invalidProps } from '@/components/FormBlock';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const NODE_ID = 'case-action-node';

/**
 * ステップの選択欄(処理するステップは選べるステップが 2 つ以上のときだけ呼ぶ)。value = ステップの id の文字列。
 */
export function CaseActionNodeSelect({
    id = NODE_ID,
    label,
    nodes,
    value,
    onChange,
    error,
}: {
    /** 欄の id(省くと処理するステップの欄。1 つのダイアログに 2 つ置くときは替える) */
    id?: string;
    label: string;
    nodes: { id: number; name: string }[];
    value: string;
    onChange: (value: string) => void;
    error: string | undefined;
}) {
    return (
        <FormBlock id={id} label={label} error={error}>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger
                    id={id}
                    className="w-full"
                    {...invalidProps(id, error)}
                >
                    <SelectValue placeholder="選択してください" />
                </SelectTrigger>
                <SelectContent>
                    {nodes.map((candidate) => (
                        <SelectItem
                            key={candidate.id}
                            value={String(candidate.id)}
                        >
                            {candidate.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </FormBlock>
    );
}
