import { ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { missingChoiceLabel } from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import type { RecordFieldDefinition } from '@/types';

type Props = {
    /** ボタンの id(ラベルと結ぶ) */
    id?: string;
    /** 選択の項目 */
    field: RecordFieldDefinition;
    /** 選んだ選択肢のコード('' = 選んでいない) */
    value: string;
    onChange: (value: string) => void;
    /** 「選択を解除」を出す */
    isClearable: boolean;
    /** 今の入力で必須か(aria-required) */
    isRequiredNow?: boolean;
    /** エラーがある(aria-invalid。フォームの最初のエラーへのフォーカスにも使う) */
    invalid?: boolean;
    /** エラーの文言の要素の id(aria-describedby) */
    describedBy?: string;
    /** ラベルを持たない欄(明細の表のセル)の名前 */
    ariaLabel?: string;
};

/**
 * 選択の項目の選択欄(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2)。形は参照の選択欄と同じ。
 * 絞り込みは見える名前だけで行う(cmdk の既定はコードの value も採点し、並びも入れ替える)。
 */
export function RecordChoicePicker({
    id,
    field,
    value,
    onChange,
    isClearable,
    isRequiredNow = false,
    invalid = false,
    describedBy,
    ariaLabel,
}: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const missing = missingChoiceLabel(field, value);
    const label =
        missing ??
        field.options.find((option) => option.code === value)?.name ??
        null;
    const term = query.trim().toLowerCase();
    const shownOptions = field.options.filter((option) =>
        option.name.toLowerCase().includes(term),
    );

    const choose = (code: string) => {
        onChange(code);
        setOpen(false);
        setQuery('');
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    setQuery('');
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={ariaLabel}
                    aria-required={isRequiredNow || undefined}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                    className="w-full justify-between font-normal"
                >
                    <span
                        className={cn(
                            'truncate',
                            label === null && 'text-muted-foreground',
                        )}
                    >
                        {label ?? '選択してください'}
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-(--radix-popover-trigger-width) min-w-64 p-0"
            >
                <Command shouldFilter={false} label={`${field.name}の選択肢`}>
                    <CommandInput
                        value={query}
                        onValueChange={setQuery}
                        placeholder="検索する語"
                    />
                    <CommandList>
                        <CommandEmpty>該当する選択肢はありません</CommandEmpty>
                        {missing !== null && term === '' && (
                            <CommandItem value={`missing:${value}`} disabled>
                                <span className="truncate">{missing}</span>
                            </CommandItem>
                        )}
                        {shownOptions.map((option) => (
                            <CommandItem
                                key={option.code}
                                value={option.code}
                                onSelect={() => choose(option.code)}
                            >
                                <span className="truncate">{option.name}</span>
                            </CommandItem>
                        ))}
                    </CommandList>
                    {isClearable && value !== '' && (
                        <div className="border-t p-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="w-full justify-start"
                                onClick={() => choose('')}
                            >
                                選択を解除
                            </Button>
                        </div>
                    )}
                </Command>
            </PopoverContent>
        </Popover>
    );
}
