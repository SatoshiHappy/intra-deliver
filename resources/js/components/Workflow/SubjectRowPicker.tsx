import { ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
import { invalidProps } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import { Command, CommandInput, CommandList } from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { AssigneeCandidateList } from '@/components/Workflow/AssigneeCandidateList';
import { useAdminSubjectCandidates } from '@/hooks/Workflow/use-admin-subject-candidates';
import { CANDIDATE_QUERY_MAX_LENGTH } from '@/hooks/use-record-candidates';
import { cn } from '@/lib/utils';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowSubjectType } from '@/types';

type Props = {
    /** 引き金のボタンの id(誤りの文言の id もここから作る) */
    id: string;
    type: WorkflowSubjectType;
    /** 選んだ相手の名前(未選択は null) */
    name: string | null;
    /** 引き金のボタンの読み上げの名前(行を区別する) */
    label: string;
    error: string | undefined;
    onPick: (picked: { id: number; name: string }) => void;
};

/**
 * 候補の検索の入力と一覧(開いている間だけ候補の口を引く。語が空なら引かない)。
 */
function SubjectCommand({
    type,
    label,
    isOpen,
    onPick,
}: Pick<Props, 'type' | 'label' | 'onPick'> & { isOpen: boolean }) {
    const [query, setQuery] = useState('');
    const candidates = useAdminSubjectCandidates(type, isOpen ? query : '');

    return (
        <Command shouldFilter={false} label={`${label}の候補`}>
            <CommandInput
                value={query}
                onValueChange={setQuery}
                maxLength={CANDIDATE_QUERY_MAX_LENGTH}
                placeholder="名前・コードで検索"
            />
            <CommandList>
                <AssigneeCandidateList
                    query={query}
                    candidates={candidates}
                    options={candidates.users ?? undefined}
                    onSelect={(candidate) =>
                        onPick({
                            id: candidate.id,
                            name: personName(candidate),
                        })
                    }
                />
            </CommandList>
        </Command>
    );
}

/**
 * 対象者の行の「対象者」の欄(選んだ相手の名前、未選択なら案内。押すと候補の検索が開き、選ぶと閉じる)。
 * 種類を替えたときは呼び手が key を替えて作り直す(前の種類の語と結果を持ち越さない)。
 */
export function SubjectRowPicker({
    id,
    type,
    name,
    label,
    error,
    onPick,
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    className="w-full min-w-48 justify-between font-normal"
                    {...invalidProps(id, error)}
                >
                    <span
                        className={cn(
                            'truncate',
                            name === null && 'text-muted-foreground',
                        )}
                    >
                        {name ?? '選択してください'}
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-0">
                {open && (
                    <SubjectCommand
                        type={type}
                        label={label}
                        isOpen={open}
                        onPick={(picked) => {
                            onPick(picked);
                            setOpen(false);
                        }}
                    />
                )}
            </PopoverContent>
        </Popover>
    );
}
