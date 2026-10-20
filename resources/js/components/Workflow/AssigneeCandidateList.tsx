import { CommandEmpty, CommandItem } from '@/components/ui/command';
import type { AssigneeCandidatesState } from '@/hooks/Workflow/use-assignee-candidates';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowAssigneeCandidate } from '@/types';

/** 待ち・失敗・該当なしの知らせ(CommandEmpty と同じ見た目) */
const noticeClass = 'py-6 text-center text-sm';

/**
 * 候補の一覧の中身(語が空・失敗・検索中・該当なし・候補)。options = 選んだ人を除いた候補(取得前は undefined)。
 */
export function AssigneeCandidateList({
    query,
    candidates,
    options,
    onSelect,
}: {
    query: string;
    candidates: AssigneeCandidatesState;
    options: WorkflowAssigneeCandidate[] | undefined;
    onSelect: (user: WorkflowAssigneeCandidate) => void;
}) {
    return (
        <>
            {query.trim() === '' && (
                <p className={noticeClass}>検索する語を入力してください</p>
            )}
            {query.trim() !== '' && candidates.hasFailed && (
                <p className={noticeClass}>候補を取得できませんでした</p>
            )}
            {query.trim() !== '' &&
                !candidates.hasFailed &&
                options === undefined && <p className={noticeClass}>検索中…</p>}
            {!candidates.hasFailed && options?.length === 0 && (
                <CommandEmpty>該当する候補はありません</CommandEmpty>
            )}
            {!candidates.hasFailed &&
                options?.map((user) => (
                    <CommandItem
                        key={user.id}
                        value={`id:${user.id}`}
                        onSelect={() => onSelect(user)}
                    >
                        <span className="truncate">{personName(user)}</span>
                        {user.mainDepartmentName !== null && (
                            <span className="ml-auto text-xs text-muted-foreground">
                                {user.mainDepartmentName}
                            </span>
                        )}
                    </CommandItem>
                ))}
        </>
    );
}
