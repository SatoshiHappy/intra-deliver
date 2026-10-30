import { ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import { PartyMark } from '@/components/PartyMark';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { CANDIDATE_QUERY_MAX_LENGTH } from '@/hooks/use-record-candidates';
import { useReferenceOptions } from '@/hooks/use-reference-options';
import type { PickedGroup } from '@/lib/pickedReference';
import { pickedGroups, pickedKey, pickedMarkOf } from '@/lib/pickedReference';
import { cn } from '@/lib/utils';
import type { PickedReference, RecordFieldDefinition } from '@/types';

type Props = {
    /** ボタンの id(ラベルと結ぶ) */
    id?: string;
    /** 参照の項目(ユーザー・組織・ユーザーと組織・他モジュール参照) */
    field: RecordFieldDefinition;
    /** 書くレコードの id(他モジュール参照の候補から除く。null = 除かない) */
    recordId: number | null;
    value: PickedReference | null;
    onChange: (value: PickedReference | null) => void;
    /** 必須なら「選択を解除」を出さない */
    isRequired?: boolean;
    /** エラーがある(aria-invalid。フォームの最初のエラーへのフォーカスにも使う) */
    invalid?: boolean;
    /** エラーの文言の要素の id(aria-describedby) */
    describedBy?: string;
    /** ラベルを持たない欄(明細の表のセル)の名前 */
    ariaLabel?: string;
};

/** 待ち・失敗・該当なしの知らせ(CommandEmpty と同じ見た目) */
const noticeClass = 'py-6 text-center text-sm';

/**
 * 候補の一覧の中身(待ち・失敗・該当なしの知らせと候補。参照の選択欄と複数の参照の欄で同じ)。
 */
export function ReferenceOptionList({
    field,
    options,
    hasFailed,
    onSelect,
}: {
    /** 候補を取る参照の項目(候補のキーと群の見出しは項目の型で決める) */
    field: RecordFieldDefinition;
    options: PickedReference[] | null;
    hasFailed: boolean;
    onSelect: (picked: PickedReference) => void;
}) {
    if (hasFailed) {
        return <p className={noticeClass}>候補を取得できませんでした</p>;
    }

    if (options === null) {
        return <p className={noticeClass}>検索中…</p>;
    }

    if (options.length === 0) {
        return <CommandEmpty>該当する候補はありません</CommandEmpty>;
    }

    const items = (group: PickedGroup) =>
        group.options.map((option) => (
            <CommandItem
                key={pickedKey(field, option)}
                value={`id:${pickedKey(field, option)}`}
                onSelect={() => onSelect(option)}
            >
                <span className="truncate">{option.label}</span>
            </CommandItem>
        ));

    return pickedGroups(field, options).map((group) =>
        group.heading === null ? (
            items(group)
        ) : (
            <CommandGroup key={group.heading} heading={group.heading}>
                {items(group)}
            </CommandGroup>
        ),
    );
}

/**
 * 選択欄のボタンの中身(選んだ値の表示名、無ければ「選択してください」。型「ユーザーと組織」の値は前に種類の印
 * = docs の 設計/業務データの入れ物/画面/README.md 1.3)。
 */
function PickedLabel({
    field,
    value,
}: {
    field: RecordFieldDefinition;
    value: PickedReference | null;
}) {
    const mark = pickedMarkOf(field, value);

    return (
        <span className="flex min-w-0 items-center gap-2">
            {mark !== null && value !== null && (
                <PartyMark
                    kind={mark}
                    label={value.label}
                    size="inline"
                    announced
                />
            )}
            <span
                className={cn(
                    'truncate',
                    value === null && 'text-muted-foreground',
                )}
            >
                {value?.label ?? '選択してください'}
            </span>
        </span>
    );
}

/**
 * 参照の選択欄(docs の 設計/業務データの入れ物/画面/README.md 4 節)。候補は口が語で絞って上限まで返すので、Command では絞らない(shouldFilter = false)。
 */
export function RecordReferencePicker({
    id,
    field,
    recordId,
    value,
    onChange,
    isRequired = false,
    invalid = false,
    describedBy,
    ariaLabel,
}: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const { options, hasFailed } = useReferenceOptions(
        field,
        recordId,
        query,
        open,
    );

    const choose = (picked: PickedReference | null) => {
        onChange(picked);
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
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                    className="w-full justify-between font-normal"
                >
                    <PickedLabel field={field} value={value} />
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-(--radix-popover-trigger-width) min-w-64 p-0"
            >
                <Command shouldFilter={false} label={`${field.name}の候補`}>
                    <CommandInput
                        value={query}
                        onValueChange={setQuery}
                        maxLength={CANDIDATE_QUERY_MAX_LENGTH}
                        placeholder="検索する語"
                    />
                    <CommandList>
                        <ReferenceOptionList
                            field={field}
                            options={options}
                            hasFailed={hasFailed}
                            onSelect={choose}
                        />
                    </CommandList>
                    {!isRequired && value !== null && (
                        <div className="border-t p-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="w-full justify-start"
                                onClick={() => choose(null)}
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
