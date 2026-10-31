import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { useAdminSubjectCandidates } from '@/hooks/Workflow/use-admin-subject-candidates';
import { CANDIDATE_QUERY_MAX_LENGTH } from '@/hooks/use-record-candidates';
import { candidateGroupsOf, subjectKey } from '@/lib/Workflow/subjectRows';
import type { SubjectRowDraft } from '@/lib/Workflow/subjectRows';
import type { WorkflowSubjectCandidate, WorkflowSubjectType } from '@/types';

/** 待ち・失敗・語が空の知らせ(CommandEmpty と同じ見た目) */
const noticeClass = 'py-6 text-center text-sm';

/** 候補を選んだときの処理(種類と候補) */
export type PickHandler = (
    type: WorkflowSubjectType,
    candidate: WorkflowSubjectCandidate,
) => void;

/**
 * 候補の一覧の前の知らせ(語が空・失敗・検索中。候補を出せるなら null)。
 */
function noticeOf(
    query: string,
    result: ReturnType<typeof useAdminSubjectCandidates>,
): string | null {
    if (query.trim() === '') {
        return '検索する語を入力してください';
    }

    if (result.hasFailed) {
        return '候補を取得できませんでした';
    }

    return result.users === null ? '検索中…' : null;
}

/**
 * 候補の一覧の中身(語が空・失敗・検索中・該当なし・種類ごとの群。選び済みの相手は出さない)。
 */
function CandidateGroups({
    query,
    rows,
    onSelect,
}: {
    query: string;
    rows: SubjectRowDraft[];
    onSelect: PickHandler;
}) {
    const result = useAdminSubjectCandidates(query);
    const notice = noticeOf(query, result);

    if (notice !== null || result.users === null) {
        return <p className={noticeClass}>{notice}</p>;
    }

    const groups = candidateGroupsOf(result.users, rows);

    if (groups.length === 0) {
        return <CommandEmpty>該当する候補はありません</CommandEmpty>;
    }

    return groups.map((group) => (
        <CommandGroup key={group.type} heading={group.label}>
            {group.candidates.map((candidate) => (
                <CommandItem
                    key={candidate.id}
                    value={`id:${subjectKey({ type: group.type, id: candidate.id })}`}
                    onSelect={() => onSelect(group.type, candidate)}
                >
                    <span className="truncate">
                        {candidate.name ?? candidate.code}
                    </span>
                    {candidate.mainDepartmentName !== null && (
                        <span className="ml-auto text-xs text-muted-foreground">
                            {candidate.mainDepartmentName}
                        </span>
                    )}
                </CommandItem>
            ))}
        </CommandGroup>
    ));
}

/**
 * 対象者の欄の検索の中身(語の入力と、1 つの語で 4 種類をまとめて引いた候補の一覧。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.4 節)。開いている間だけ口を引く(閉じると呼び手が語を空にする)。
 */
export function SubjectCandidateCommand({
    label,
    rows,
    query,
    onQueryChange,
    onSelect,
}: {
    label: string;
    rows: SubjectRowDraft[];
    query: string;
    onQueryChange: (query: string) => void;
    onSelect: PickHandler;
}) {
    return (
        <Command shouldFilter={false} label={`${label}の候補`}>
            <CommandInput
                value={query}
                onValueChange={onQueryChange}
                maxLength={CANDIDATE_QUERY_MAX_LENGTH}
                placeholder="検索する語"
            />
            <CommandList>
                <CandidateGroups
                    query={query}
                    rows={rows}
                    onSelect={onSelect}
                />
            </CommandList>
        </Command>
    );
}
