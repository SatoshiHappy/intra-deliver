import { Check, ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps } from 'react';
import { invalidProps } from '@/components/FormBlock';
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
import {
    SUBJECT_QUERY_MAX_LENGTH,
    useSubjectCandidates,
} from '@/hooks/Authz/useSubjectCandidates';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { AuthzSubjectCandidate, AuthzSubjectKind } from '@/types';

/** 待ち・失敗・該当なしの知らせ(CommandEmpty と同じ見た目) */
const noticeClass = 'py-6 text-center text-sm';

type Props = {
    /** ボタンの id(ラベルと結ぶ) */
    id: string;
    kind: AuthzSubjectKind;
    value: AuthzSubjectCandidate | null;
    onChange: (value: AuthzSubjectCandidate) => void;
    /** 欄の下のエラーの文言(あれば aria-invalid と aria-describedby を付ける) */
    error: string | undefined;
};

/**
 * 候補の一覧の中身。ユーザーは主所属の名前を添える(同じ名前の人を見分けるため)。
 */
function CandidateOptions({
    candidates,
    selectedId,
    onSelect,
}: {
    candidates: AuthzSubjectCandidate[];
    selectedId: number | undefined;
    onSelect: (candidate: AuthzSubjectCandidate) => void;
}) {
    if (candidates.length === 0) {
        return <CommandEmpty>該当する候補はありません</CommandEmpty>;
    }

    return candidates.map((candidate) => (
        <CommandItem
            key={candidate.id}
            value={`id:${candidate.id}`}
            onSelect={() => onSelect(candidate)}
        >
            <Check
                className={cn(
                    'size-4',
                    candidate.id !== selectedId && 'invisible',
                )}
            />
            <span className="truncate">{nameOrCode(candidate)}</span>
            {candidate.mainDepartmentName !== null && (
                <span className="ml-auto truncate text-xs text-muted-foreground">
                    {candidate.mainDepartmentName}
                </span>
            )}
        </CommandItem>
    ));
}

/**
 * 検索の入力と候補の一覧。語が空なら候補は出さない。候補は口が語で絞るので、Command では絞らない。
 */
function CandidateCommand({
    kind,
    query,
    onQueryChange,
    selectedId,
    onSelect,
}: {
    kind: AuthzSubjectKind;
    query: string;
    onQueryChange: (query: string) => void;
    selectedId: number | undefined;
    onSelect: (candidate: AuthzSubjectCandidate) => void;
}) {
    const { result, hasFailed } = useSubjectCandidates(kind, query);
    const typed = query.trim() !== '';

    return (
        <Command shouldFilter={false} label="対象者の候補">
            <CommandInput
                value={query}
                onValueChange={onQueryChange}
                maxLength={SUBJECT_QUERY_MAX_LENGTH}
                placeholder="検索する語"
            />
            <CommandList>
                {typed && hasFailed && (
                    <p className={noticeClass}>候補を取得できませんでした</p>
                )}
                {typed && !hasFailed && result === null && (
                    <p className={noticeClass}>検索中…</p>
                )}
                {typed && !hasFailed && result !== null && (
                    <CandidateOptions
                        candidates={result.candidates}
                        selectedId={selectedId}
                        onSelect={onSelect}
                    />
                )}
            </CommandList>
        </Command>
    );
}

/**
 * 選択欄のボタン(選んだ候補の名前、未選択なら案内)。PopoverTrigger の asChild が属性と ref を足すので、残りは Button に渡す。
 */
function PickerTrigger({
    picked,
    ...props
}: ComponentProps<typeof Button> & { picked: AuthzSubjectCandidate | null }) {
    return (
        <Button
            type="button"
            variant="outline"
            role="combobox"
            className="w-full justify-between font-normal"
            {...props}
        >
            <span
                className={cn(
                    'truncate',
                    picked === null && 'text-muted-foreground',
                )}
            >
                {picked === null ? '選択してください' : nameOrCode(picked)}
            </span>
            <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
        </Button>
    );
}

/**
 * 候補の選択欄(語を入力すると候補の口から取る。語が空なら候補は出さない)。候補は口が語で絞るので、Command では絞らない。
 * 種類を替えたときは呼び手が key を替えて作り直す(前の種類の語と結果を持ち越さない)。
 */
export function SubjectCandidatePicker({
    id,
    kind,
    value,
    onChange,
    error,
}: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

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
                <PickerTrigger
                    id={id}
                    picked={value}
                    aria-expanded={open}
                    {...invalidProps(id, error)}
                />
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-(--radix-popover-trigger-width) min-w-64 p-0"
            >
                <CandidateCommand
                    kind={kind}
                    query={query}
                    onQueryChange={setQuery}
                    selectedId={value?.id}
                    onSelect={(candidate) => {
                        onChange(candidate);
                        setOpen(false);
                        setQuery('');
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}
