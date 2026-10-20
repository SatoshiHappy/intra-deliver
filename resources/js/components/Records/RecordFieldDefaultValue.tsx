import { DateInput } from '@/components/DateInput';
import { FormBlock, invalidProps } from '@/components/FormBlock';
import { RecordFieldInput } from '@/components/RecordFieldInput';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    DefaultValueFields,
    DefaultValueFormData,
} from '@/lib/Records/fieldDefaultValue';
import type { DefaultMode, RelativeUnit } from '@/lib/Records/relativeDefault';
import {
    DEFAULT_MODE_LABELS,
    hasRelativeDefault,
    RELATIVE_UNIT_LABELS,
} from '@/lib/Records/relativeDefault';
import type { RecordFieldDefinition } from '@/types';

/** 既定値の欄の id(ラベル「既定値」と誤りの文言を結ぶ) */
const ID = 'field-default_value';

type Props = {
    data: DefaultValueFormData;
    /** 既定値の欄を描くための項目の定義(null = 既定値を持てない型) */
    field: RecordFieldDefinition | null;
    error: string | undefined;
    onChange: (patch: Partial<DefaultValueFields>) => void;
};

/**
 * 「登録の時から」の数と単位(持ち方の選択から続けて文として読める順に並べる。数の誤りはほかの欄と同じく送った後の 422 で出す)。
 */
function RelativeInputs({ data, error, onChange }: Omit<Props, 'field'>) {
    return (
        <>
            <Input
                id={`${ID}-amount`}
                inputMode="numeric"
                aria-label="登録の時からの数"
                value={data.relativeAmount}
                onChange={(event) =>
                    onChange({ relativeAmount: event.target.value })
                }
                className="max-w-24 font-num tabular-nums"
                {...invalidProps(ID, error)}
            />
            <Select
                value={data.relativeUnit}
                onValueChange={(unit) =>
                    onChange({ relativeUnit: unit as RelativeUnit })
                }
            >
                <SelectTrigger aria-label="単位" className="w-24">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {Object.entries(RELATIVE_UNIT_LABELS).map(
                        ([unit, label]) => (
                            <SelectItem key={unit} value={unit}>
                                {label}
                            </SelectItem>
                        ),
                    )}
                </SelectContent>
            </Select>
            <span className="text-sm">後</span>
        </>
    );
}

/**
 * 日付・日時の固定の値(業務のフォームと同じ日付・日時の欄。誤りの文言は欄の全体の誤りに結ぶ)。
 */
function FixedInput({ data, error, onChange }: Omit<Props, 'field'>) {
    return (
        <DateInput
            id={`${ID}-fixed`}
            type={data.type === 'date' ? 'date' : 'datetime-local'}
            aria-label="既定値の固定の値"
            value={
                typeof data.defaultValue === 'string' ? data.defaultValue : ''
            }
            onChange={(event) => onChange({ defaultValue: event.target.value })}
            className="font-num tabular-nums"
            {...invalidProps(ID, error)}
        />
    );
}

/**
 * 日付・日時の既定値の持ち方(指定しない・固定の値・登録の時から)。
 */
function ModeSelect({
    mode,
    onChange,
}: {
    mode: DefaultMode;
    onChange: (mode: DefaultMode) => void;
}) {
    return (
        <Select
            value={mode}
            onValueChange={(next) => onChange(next as DefaultMode)}
        >
            <SelectTrigger id={ID} aria-label="既定値の指定" className="w-40">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {Object.entries(DEFAULT_MODE_LABELS).map(([value, label]) => (
                    <SelectItem key={value} value={value}>
                        {label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * 項目の追加・編集の既定値の欄(画面/管理の画面.md 3.4)。日付・日時は持ち方(指定しない・固定の値・登録の時から)を選び、
 * 固定の値は業務のフォームと同じ入力、登録の時からは数と単位。ほかの型は業務のフォームと同じ入力だけ。
 */
export function RecordFieldDefaultValue({
    data,
    field,
    error,
    onChange,
}: Props) {
    if (field === null) {
        return null;
    }

    if (!hasRelativeDefault(data.type)) {
        return (
            <FormBlock id={ID} label="既定値" error={error}>
                <RecordFieldInput
                    id={ID}
                    field={field}
                    recordId={null}
                    draft={data.defaultValue}
                    onChange={(value) => onChange({ defaultValue: value })}
                    isRequiredNow={false}
                    invalid={error !== undefined}
                />
            </FormBlock>
        );
    }

    // ラベル「既定値」は持ち方の選択に結び、値の欄は別の id と名前を持つ(誤りの印は値の欄に置く)
    return (
        <FormBlock id={ID} label="既定値" error={error}>
            <div className="flex flex-wrap items-center gap-2">
                <ModeSelect
                    mode={data.defaultMode}
                    onChange={(mode) => onChange({ defaultMode: mode })}
                />
                {data.defaultMode === 'fixed' && (
                    <FixedInput data={data} error={error} onChange={onChange} />
                )}
                {data.defaultMode === 'relative' && (
                    <RelativeInputs
                        data={data}
                        error={error}
                        onChange={onChange}
                    />
                )}
            </div>
        </FormBlock>
    );
}
