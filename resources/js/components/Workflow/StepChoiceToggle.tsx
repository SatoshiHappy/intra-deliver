import { FormBlock, invalidProps } from '@/components/FormBlock';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

/** 選択肢の 1 つ(value は欄の値、label は画面の語) */
type Choice<T extends string> = { value: T; label: string };

type Props<T extends string> = {
    id: string;
    label: string;
    value: T | '';
    choices: Choice<T>[];
    error: string | undefined;
    help?: string;
    onChange: (value: T) => void;
};

/** ボタン 1 つ(選んだボタン = 白地・緑の太字・縁の線。ほかは地の上の薄字) */
const ITEM_CLASSES = cn(
    'flex-1 rounded-md text-muted-foreground hover:bg-transparent hover:text-foreground data-[spacing=0]:rounded-md',
    'data-[state=on]:bg-background data-[state=on]:font-semibold data-[state=on]:text-primary',
    'data-[state=on]:shadow-[0_0_0_1px_var(--border)]',
);

/**
 * ステップの設定の切り替えボタンの欄(ラベル・「?」・切り替えボタンの並び・誤り。基準にする人・可決の条件)。
 * 1 つだけ選べ、選んだボタンをもう一度押しても外れない(値が空にならない)。矢印キーでボタンを移り、Space・Enter で選ぶ(Radix の ToggleGroup)。
 * 地と選んだボタンの見た目は、定義の管理の画面のモックの切り替え(.seg)に合わせる。
 */
export function StepChoiceToggle<T extends string>({
    id,
    label,
    value,
    choices,
    error,
    help,
    onChange,
}: Props<T>) {
    return (
        <FormBlock id={id} label={label} help={help} error={error}>
            <ToggleGroup
                type="single"
                id={id}
                aria-labelledby={`${id}-label`}
                value={value}
                onValueChange={(next) => {
                    const choice = choices.find((one) => one.value === next);

                    if (choice !== undefined) {
                        onChange(choice.value);
                    }
                }}
                className="w-full gap-0.5 rounded-lg bg-status-settled p-0.5"
                {...invalidProps(id, error, help !== undefined)}
            >
                {choices.map((choice) => (
                    <ToggleGroupItem
                        key={choice.value}
                        value={choice.value}
                        size="sm"
                        className={ITEM_CLASSES}
                    >
                        {choice.label}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
        </FormBlock>
    );
}
