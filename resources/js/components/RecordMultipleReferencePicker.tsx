import { Search, X } from 'lucide-react';
import { useState } from 'react';
import { PartyMark } from '@/components/PartyMark';
import { ReferenceOptionList } from '@/components/RecordReferencePicker';
import { Button } from '@/components/ui/button';
import { Command, CommandInput, CommandList } from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { CANDIDATE_QUERY_MAX_LENGTH } from '@/hooks/use-record-candidates';
import { useReferenceOptions } from '@/hooks/use-reference-options';
import { pickedKey, unpickedOptions } from '@/lib/pickedReference';
import { MAX_REFERENCE_TARGETS } from '@/lib/recordForm';
import { hasPartyKind, partyKindOf } from '@/lib/recordParty';
import type { ReferenceItemErrors } from '@/lib/recordFormErrors';
import { cn } from '@/lib/utils';
import type { PickedReference, RecordFieldDefinition } from '@/types';

type Props = {
    /** 検索の欄のボタンの id(ラベルと結ぶ) */
    id?: string;
    /** 複数選べる参照の項目(ユーザー・組織・ユーザーと組織) */
    field: RecordFieldDefinition;
    /** 選んだ相手(並びの順) */
    value: PickedReference[];
    onChange: (value: PickedReference[]) => void;
    /** 欄にエラーがある(検索の欄の aria-invalid) */
    invalid?: boolean;
    /** 添字の誤り(チップの印と、外したときの消し方) */
    itemErrors?: ReferenceItemErrors;
    /** エラーの文言の要素の id(aria-describedby) */
    describedBy?: string;
    /** ラベルを持たない欄(明細の表のセル)の名前 */
    ariaLabel?: string;
};

/**
 * チップの頭の印(ユーザーは頭文字の丸、組織は組織のアイコン。合意したモックの形)。型「ユーザーと組織」は相手の種類で決め、種類を読み上げる。
 */
function ChipMark({
    field,
    picked,
}: {
    field: RecordFieldDefinition;
    picked: PickedReference;
}) {
    const isParty = hasPartyKind(field);
    const kind = isParty
        ? partyKindOf(field, picked)
        : field.type === 'user'
          ? 'user'
          : 'department';

    return (
        <PartyMark
            kind={kind}
            label={picked.label}
            size="chip"
            announced={isParty}
        />
    );
}

/**
 * 選んだ相手のチップ 1 つ(頭の印・表示名・所属と、右に外す ×)。誤りがあれば赤の枠で、外すボタンに aria-invalid を付ける
 * (送った後のフォーカスの行き先にする)。
 */
function ReferenceChip({
    field,
    picked,
    invalid,
    describedBy,
    onRemove,
}: {
    field: RecordFieldDefinition;
    picked: PickedReference;
    invalid: boolean;
    describedBy?: string;
    onRemove: () => void;
}) {
    return (
        <li
            className={cn(
                'flex h-9 max-w-full items-center gap-2 rounded-full border bg-background pr-1 pl-1 text-sm',
                invalid && 'border-destructive',
            )}
        >
            <ChipMark field={field} picked={picked} />
            <span className="truncate font-medium">{picked.label}</span>
            {(picked.affiliation ?? null) !== null && (
                <span className="truncate text-xs text-muted-foreground">
                    {picked.affiliation}
                </span>
            )}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-6 rounded-full"
                aria-label={`${picked.label}を外す`}
                aria-invalid={invalid || undefined}
                aria-describedby={invalid ? describedBy : undefined}
                onClick={onRemove}
            >
                <X aria-hidden="true" />
            </Button>
        </li>
    );
}

/**
 * 開いた検索の欄の中身(語の入力と候補。参照の選択欄と同じ候補と検索で、選び済みの相手は出さない)。閉じると外され、語も空に戻る。
 */
function ReferenceCandidates({
    field,
    value,
    onSelect,
}: {
    field: RecordFieldDefinition;
    value: PickedReference[];
    onSelect: (picked: PickedReference) => void;
}) {
    const [query, setQuery] = useState('');
    const { options, hasFailed } = useReferenceOptions(
        field,
        null,
        query,
        true,
    );

    return (
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
                    options={
                        options === null
                            ? null
                            : unpickedOptions(field, options, value)
                    }
                    hasFailed={hasFailed}
                    onSelect={onSelect}
                />
            </CommandList>
        </Command>
    );
}

/**
 * チップの下の検索の欄(空のときの文字「<項目の名前>を追加」。上限に達したら押せない)。候補を選ぶと閉じる。
 */
function ReferenceSearch({
    id,
    field,
    value,
    onPick,
    invalid,
    describedBy,
    ariaLabel,
}: Omit<Props, 'onChange' | 'itemErrors'> & {
    onPick: (picked: PickedReference) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
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
                    disabled={value.length >= MAX_REFERENCE_TARGETS}
                    className="w-full justify-between font-normal"
                >
                    <span className="truncate text-muted-foreground">{`${field.name}を追加`}</span>
                    <Search className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-(--radix-popover-trigger-width) min-w-64 p-0"
            >
                <ReferenceCandidates
                    field={field}
                    value={value}
                    onSelect={(picked) => {
                        onPick(picked);
                        setOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}

/**
 * 複数の参照の欄(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2 の「複数の参照の欄」): 選んだ相手をチップで並びの順に並べ、
 * その下に検索の欄を置く。候補を選ぶとチップを末尾に足す。チップを外すと、その項目の添字のキーの誤りを消す(「エラー」)。
 */
export function RecordMultipleReferencePicker({
    onChange,
    itemErrors,
    ...props
}: Props) {
    const { field, value, describedBy } = props;
    const remove = (index: number) => {
        onChange(value.filter((_, position) => position !== index));
        itemErrors?.onRemoved();
    };

    return (
        <div className="flex w-full flex-col gap-2">
            {value.length > 0 && (
                <ul
                    className="flex flex-wrap gap-2"
                    aria-label={`${props.ariaLabel ?? field.name}の選択`}
                >
                    {value.map((picked, index) => (
                        <ReferenceChip
                            key={pickedKey(field, picked)}
                            field={field}
                            picked={picked}
                            invalid={
                                itemErrors?.invalidIndexes.includes(index) ??
                                false
                            }
                            describedBy={describedBy}
                            onRemove={() => remove(index)}
                        />
                    ))}
                </ul>
            )}
            <ReferenceSearch
                {...props}
                onPick={(picked) => onChange([...value, picked])}
            />
        </div>
    );
}
