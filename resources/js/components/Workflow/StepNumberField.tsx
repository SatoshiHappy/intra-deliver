import { FormBlock, invalidProps } from '@/components/FormBlock';
import { Input } from '@/components/ui/input';

/**
 * ステップの設定の数の欄(打った文字のまま持つ。範囲の誤りはサーバーが欄の下に返す)。hint は入力に応じて変わる状態の文。
 */
export function StepNumberField({
    id,
    label,
    value,
    error,
    help,
    hint,
    isDisabled = false,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    error: string | undefined;
    help?: string;
    hint?: string;
    isDisabled?: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <FormBlock id={id} label={label} help={help} hint={hint} error={error}>
            <Input
                id={id}
                type="number"
                inputMode="numeric"
                value={value}
                disabled={isDisabled}
                onChange={(event) => onChange(event.target.value)}
                {...invalidProps(id, error, help !== undefined)}
            />
        </FormBlock>
    );
}
