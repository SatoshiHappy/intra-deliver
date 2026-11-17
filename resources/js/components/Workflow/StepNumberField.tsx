import { FormBlock, formErrorId, invalidProps } from '@/components/FormBlock';
import { Input } from '@/components/ui/input';

/** 欄の値と誤り(誤りはサーバーが欄の下に返す) */
type FieldProps = {
    id: string;
    value: string;
    error: string | undefined;
    isDisabled?: boolean;
    onChange: (value: string) => void;
};

/**
 * 欄の名前の出し方。label = 欄の名前を出す(hint は入力に応じて変わる状態の文)。labelledBy = 欄の名前を出さず、入力の読み上げの名前を
 * 見出しの要素(id)から取る(欄が 1 つだけのカード = docs の 設計/画面の意匠/README.md の方針 9 の (f))。unit は入力の右の単位。
 */
type Naming =
    | { label: string; help?: string; hint?: string }
    | { labelledBy: string; unit?: string };

/**
 * 数の入力。type="number" を使わず、打った文字のまま持つ(ブラウザが数と読めない文字を空にし、空欄として黙って送られるため =
 * docs の 設計/画面の意匠/README.md の方針 9 の (g))。
 */
function NumberInput({
    id,
    value,
    isDisabled = false,
    onChange,
    ...attributes
}: Omit<FieldProps, 'error'> & {
    className?: string;
    'aria-labelledby'?: string;
    'aria-invalid'?: true;
    'aria-describedby'?: string;
}) {
    return (
        <Input
            id={id}
            inputMode="numeric"
            value={value}
            disabled={isDisabled}
            onChange={(event) => onChange(event.target.value)}
            {...attributes}
        />
    );
}

/** 欄の名前を出さない数の欄(入力と単位を横に並べ、誤りをその下に出す) */
function HeadingNamedField({
    labelledBy,
    unit,
    error,
    ...input
}: FieldProps & { labelledBy: string; unit?: string }) {
    return (
        <>
            <div className="flex items-center gap-2 text-sm">
                <NumberInput
                    {...input}
                    className="w-16"
                    aria-labelledby={labelledBy}
                    {...invalidProps(input.id, error)}
                />
                {unit !== undefined && <span>{unit}</span>}
            </div>
            {error !== undefined && (
                <p
                    id={formErrorId(input.id)}
                    className="text-sm text-destructive"
                >
                    {error}
                </p>
            )}
        </>
    );
}

/**
 * ステップの設定の数の欄(人数の最小・最大・可決の値・処理期限)。範囲と形の誤りはサーバーが欄の下に返す。
 */
export function StepNumberField(props: FieldProps & Naming) {
    if ('labelledBy' in props) {
        return <HeadingNamedField {...props} />;
    }

    const { label, help, hint, error, ...input } = props;

    return (
        <FormBlock
            id={input.id}
            label={label}
            help={help}
            hint={hint}
            error={error}
        >
            <NumberInput
                {...input}
                {...invalidProps(input.id, error, help !== undefined)}
            />
        </FormBlock>
    );
}
