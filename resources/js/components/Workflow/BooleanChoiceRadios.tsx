import { invalidProps } from '@/components/FormBlock';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    choiceKeyOf,
    choiceValueOf,
    type BooleanChoice,
} from '@/lib/Workflow/booleanChoice';

/**
 * 真偽 2 択のラジオ(面「添付ファイル」の「使用する / 使用しない」、カード「添付ファイル」の「追加できる / 追加できない」)。
 * 名前は labelledBy の見出しの要素。誤りの文言があれば、呼び手が <id>-error の要素を欄の下に出す(invalidProps が結ぶ)。
 */
export function BooleanChoiceRadios({
    id,
    labelledBy,
    value,
    choices,
    error,
    onChange,
}: {
    id: string;
    labelledBy: string;
    value: boolean;
    choices: BooleanChoice[];
    error: string | undefined;
    onChange: (value: boolean) => void;
}) {
    return (
        <RadioGroup
            value={choiceKeyOf(value)}
            onValueChange={(key) => {
                const next = choiceValueOf(key, choices);

                if (next !== null) {
                    onChange(next);
                }
            }}
            aria-labelledby={labelledBy}
            {...invalidProps(id, error)}
            className="flex gap-6"
        >
            {choices.map((choice) => {
                const key = choiceKeyOf(choice.value);

                return (
                    <div key={key} className="flex items-center gap-2">
                        <RadioGroupItem id={`${id}-${key}`} value={key} />
                        <Label htmlFor={`${id}-${key}`}>{choice.label}</Label>
                    </div>
                );
            })}
        </RadioGroup>
    );
}
