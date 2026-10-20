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
import { useMasterSearch } from '@/hooks/use-master-search';
import {
    CANDIDATE_QUERY_MAX_LENGTH,
    useRecordCandidates,
} from '@/hooks/use-record-candidates';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { PickedReference, RecordFieldDefinition } from '@/types';

type Props = {
    /** ボタンの id(ラベルと結ぶ) */
    id?: string;
    /** 参照の項目(ユーザー・組織・他モジュール参照) */
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
 * 候補の一覧(ユーザー・組織は共通マスタの検索の口の群を 1 つだけ、他モジュール参照は候補の口。群なし = 設計/業務データの入れ物/画面/README.md 4 節)。
 * 開いている間は必ず取るので、options が null なら結果を待っている(失敗は hasFailed)。ユーザーは主所属の名前を所属に持つ
 * (複数の参照の欄のチップに出す)。
 */
export function useReferenceOptions(
    field: RecordFieldDefinition,
    recordId: number | null,
    query: string,
    open: boolean,
): {
    options: PickedReference[] | null;
    hasFailed: boolean;
} {
    const isRecord = field.type === 'record';
    // 開いている間は、語が空でも先頭の候補を取る(閉じると語を空にして結果を捨てる)。使わない側の口は呼ばない。
    const master = useMasterSearch(isRecord ? '' : query, open && !isRecord);
    const records = useRecordCandidates(
        field.referencedModule?.code ?? '',
        isRecord ? query : '',
        recordId,
        open && isRecord,
    );

    if (isRecord) {
        return {
            options:
                records.result?.records.map((record) => ({
                    id: record.id,
                    label: record.label ?? `#${record.id}`,
                })) ?? null,
            hasFailed: records.hasFailed,
        };
    }

    const found =
        field.type === 'user'
            ? master.result?.users.map((user) => ({
                  id: user.id,
                  label: nameOrCode(user),
                  affiliation:
                      user.mainDepartment === null
                          ? null
                          : nameOrCode(user.mainDepartment),
              }))
            : master.result?.departments.map((department) => ({
                  id: department.id,
                  label: nameOrCode(department),
              }));

    return { options: found ?? null, hasFailed: master.hasFailed };
}

/**
 * 候補の一覧の中身(待ち・失敗・該当なしの知らせと候補。参照の選択欄と複数の参照の欄で同じ)。
 */
export function ReferenceOptionList({
    options,
    hasFailed,
    onSelect,
}: {
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

    return options.map((option) => (
        <CommandItem
            key={option.id}
            value={`id:${option.id}`}
            onSelect={() => onSelect(option)}
        >
            <span className="truncate">{option.label}</span>
        </CommandItem>
    ));
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
                    <span
                        className={cn(
                            'truncate',
                            value === null && 'text-muted-foreground',
                        )}
                    >
                        {value?.label ?? '選択してください'}
                    </span>
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
