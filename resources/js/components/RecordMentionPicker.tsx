import { Search, X } from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useMentionCandidates } from '@/hooks/use-mention-candidates';
import { CANDIDATE_QUERY_MAX_LENGTH } from '@/hooks/use-record-candidates';
import { candidateNotice, MENTIONS_MAX } from '@/lib/recordComments';
import type { RecordMentionCandidate } from '@/types';

type Props = {
    /** 選んだ宛先(選んだ順) */
    value: RecordMentionCandidate[];
    onChange: (value: RecordMentionCandidate[]) => void;
    /** 宛先の候補の口の URL(JSON の配列を返す。語は ?q= で付ける。部品はルートの名前を知らない) */
    candidatesUrl: string;
    disabled?: boolean;
};

/** 選んだ宛先のチップ 1 つ(頭文字の丸・名前・所属と、右に外す ×) */
function MentionChip({
    person,
    disabled,
    onRemove,
}: {
    person: RecordMentionCandidate;
    disabled: boolean;
    onRemove: () => void;
}) {
    return (
        <li className="flex h-9 max-w-full items-center gap-2 rounded-full border bg-background pr-1 pl-1 text-sm">
            <Avatar className="size-[26px]" aria-hidden="true">
                <AvatarFallback className="bg-primary text-xs font-bold text-primary-foreground">
                    {Array.from(person.name)[0]}
                </AvatarFallback>
            </Avatar>
            <span className="truncate font-medium">{person.name}</span>
            {person.department !== null && (
                <span className="truncate text-xs text-muted-foreground">
                    {person.department}
                </span>
            )}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-6 rounded-full"
                aria-label={`${person.name}を外す`}
                disabled={disabled}
                onClick={onRemove}
            >
                <X aria-hidden="true" />
            </Button>
        </li>
    );
}

/** 開いた検索の欄の中身(語の入力と候補。選び済みの人は出さない)。閉じると外され、語も空に戻る */
function MentionCandidates({
    value,
    candidatesUrl,
    onSelect,
}: Pick<Props, 'value' | 'candidatesUrl'> & {
    onSelect: (person: RecordMentionCandidate) => void;
}) {
    const [query, setQuery] = useState('');
    const state = useMentionCandidates(candidatesUrl, query);
    const picked = new Set(value.map((person) => person.id));
    const options = (state.result ?? []).filter(
        (person) => !picked.has(person.id),
    );
    const notice = candidateNotice(query, {
        waiting: state.isWaiting,
        failed: state.hasFailed,
        found: state.result === null ? null : options.length,
    });

    return (
        <Command shouldFilter={false} label="宛先の候補">
            <CommandInput
                value={query}
                onValueChange={setQuery}
                maxLength={CANDIDATE_QUERY_MAX_LENGTH}
                placeholder="検索する語"
            />
            <CommandList>
                {notice !== null ? (
                    <p className="py-6 text-center text-sm">{notice}</p>
                ) : (
                    options.map((person) => (
                        <CommandItem
                            key={person.id}
                            value={`id:${person.id}`}
                            onSelect={() => onSelect(person)}
                        >
                            <span className="truncate">{person.name}</span>
                            {person.department !== null && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {person.department}
                                </span>
                            )}
                        </CommandItem>
                    ))
                )}
            </CommandList>
        </Command>
    );
}

/** チップの下の検索の欄「宛先を追加」(押すと候補の入力を開く。候補を選ぶと閉じる。上限に達したら押せない) */
function MentionSearch({
    value,
    onPick,
    candidatesUrl,
    disabled,
}: Props & { onPick: (person: RecordMentionCandidate) => void }) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label="宛先を追加"
                    disabled={disabled || value.length >= MENTIONS_MAX}
                    className="w-full justify-between font-normal"
                >
                    <span className="truncate text-muted-foreground">
                        宛先を追加
                    </span>
                    <Search className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-(--radix-popover-trigger-width) min-w-64 p-0"
            >
                <MentionCandidates
                    value={value}
                    candidatesUrl={candidatesUrl}
                    onSelect={(person) => {
                        onPick(person);
                        setOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}

/**
 * コメントの宛先の選択(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 7 節): 選んだ人をチップで選んだ順に並べ、その下に
 * 検索の欄「宛先を追加」を置く。候補はそのレコードを閲覧できるユーザーだけ(口が絞る)で、語を入力してから引く。上限は 10 人。
 */
export function RecordMentionPicker(props: Props) {
    const { value, onChange, disabled = false } = props;

    return (
        <div className="flex w-full flex-col gap-2">
            {value.length > 0 && (
                <ul className="flex flex-wrap gap-2" aria-label="選んだ宛先">
                    {value.map((person) => (
                        <MentionChip
                            key={person.id}
                            person={person}
                            disabled={disabled}
                            onRemove={() =>
                                onChange(
                                    value.filter(
                                        (picked) => picked.id !== person.id,
                                    ),
                                )
                            }
                        />
                    ))}
                </ul>
            )}
            <MentionSearch
                {...props}
                disabled={disabled}
                onPick={(person) => onChange([...value, person])}
            />
        </div>
    );
}
