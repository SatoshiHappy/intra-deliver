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
 * 処理するステップの選択欄(選べるステップが 2 つ以上のときだけ呼ぶ)。value = ステップの id の文字列。
 */
export function CaseActionNodeSelect({
    label,
    nodes,
    value,
    onChange,
    error,
}: {
    label: string;
    nodes: { id: number; name: string }[];
    value: string;
    onChange: (value: string) => void;
    error: string | undefined;
}) {
    return (
        <FormBlock id={NODE_ID} label={label} error={error}>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger
                    id={NODE_ID}
                    className="w-full"
                    {...invalidProps(NODE_ID, error)}
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
