import { FormBlock, invalidProps } from '@/components/FormBlock';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { SELECT_NONE } from '@/lib/selectNone';

/** 選択肢の 1 つ(value は欄の値、label は画面の語) */
type Choice<T extends string> = { value: T; label: string };

type Props<T extends string> = {
    id: string;
    label: string;
    value: T | '';
    choices: Choice<T>[];
    error: string | undefined;
    help?: string;
    /** 「指定なし」を選べる欄の語(選ぶと値は空) */
    noneLabel?: string;
    onChange: (value: T | '') => void;
};

/**
 * ステップの設定の選択の欄(ラベル・「?」・Select・誤り)。値が空なら「選択してください」。noneLabel があれば、その語で空に戻せる。
 */
export function StepChoiceSelect<T extends string>({
    id,
    label,
    value,
    choices,
    error,
    help,
    noneLabel,
    onChange,
}: Props<T>) {
    const selected =
        noneLabel !== undefined && value === '' ? SELECT_NONE : value;

    return (
        <FormBlock id={id} label={label} help={help} error={error}>
            <Select
                value={selected}
                onValueChange={(next) => {
                    const choice = choices.find((one) => one.value === next);

                    onChange(choice === undefined ? '' : choice.value);
                }}
            >
                <SelectTrigger
                    id={id}
                    className="w-full"
                    {...invalidProps(id, error, help !== undefined)}
                >
                    <SelectValue placeholder="選択してください" />
                </SelectTrigger>
                <SelectContent>
                    {noneLabel !== undefined && (
                        <SelectItem value={SELECT_NONE}>{noneLabel}</SelectItem>
                    )}
                    {choices.map((choice) => (
                        <SelectItem key={choice.value} value={choice.value}>
                            {choice.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </FormBlock>
    );
}
