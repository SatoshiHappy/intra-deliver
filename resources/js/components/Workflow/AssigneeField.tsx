import { Plus } from 'lucide-react';
import { useState } from 'react';
import { AssigneeCandidateList } from '@/components/Workflow/AssigneeCandidateList';
import { AssigneePickedList } from '@/components/Workflow/AssigneePickedList';
import { FormBlock, invalidProps } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import { Command, CommandInput, CommandList } from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useAssigneeCandidates } from '@/hooks/Workflow/use-assignee-candidates';
import { CANDIDATE_QUERY_MAX_LENGTH } from '@/hooks/use-record-candidates';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';
import type { WorkflowDesignation } from '@/types';

/**
 * 「選択中 {n} 人」の後ろの文(ルートの深さ/画面.md 6 節)。必要な数はサーバーの表 `decision.required` を引き、画面は式を持たない。
 */
function statusNoteOf(
    assigneeField: WorkflowDesignation,
    count: number,
): string {
    if (count === 0 && assigneeField.min === 0) {
        return '　このステップは省略されます。';
    }

    if (assigneeField.decision === null || count === 0) {
        return '';
    }

    // 表に無い人数(最大を超える)では文を出さない
    const required = assigneeField.decision.required[count - 1];

    return required !== undefined && count >= required
        ? `　完了の条件 ${count} 人中 ${required} 人が承認すると、合議は完了します。`
        : '';
}

/**
 * 承認のダイアログの指定の欄 1 つ(指定されるステップごと。ルートの深さ/画面.md 6 節)。選んだ人の並び・候補の検索(Popover + Command。候補 = 処理対象者の候補の口)・
 * 状態の 1 行からなる。開閉と検索語はこの部品が持つ。エラー(assignments.<id>)は欄の下。
 */
export function AssigneeField({
    caseId,
    assigneeField,
    picked,
    onChange,
    error,
}: {
    caseId: number;
    assigneeField: WorkflowDesignation;
    picked: PickedUser[];
    onChange: (picked: PickedUser[]) => void;
    error?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const candidates = useAssigneeCandidates(
        caseId,
        assigneeField.nodeId,
        open ? query : '',
    );
    const id = `case-action-assignee-${assigneeField.nodeId}`;
    const headingId = `${id}-heading`;
    const pickedIds = new Set(picked.map((person) => person.id));
    const options = candidates.users?.filter((user) => !pickedIds.has(user.id));

    return (
        <section aria-labelledby={headingId} className="grid gap-2">
            <h3 id={headingId} className="text-sm font-semibold">
                {`指定するステップ　${assigneeField.kindLabel} ${assigneeField.name}`}
            </h3>
            <FormBlock
                id={id}
                label="処理担当者"
                help={`${assigneeField.min} 人から ${assigneeField.max} 人まで選択できます。`}
                error={error}
            >
                <div className="grid gap-2">
                    {picked.length > 0 && (
                        <AssigneePickedList
                            fieldName={assigneeField.name}
                            picked={picked}
                            isSerial={assigneeField.kind === 'serial'}
                            onChange={onChange}
                        />
                    )}
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
                                className="w-fit"
                                aria-label="ユーザーを検索して追加"
                                aria-expanded={open}
                                {...invalidProps(id, error, true)}
                            >
                                <Plus aria-hidden="true" />
                                ユーザーを検索して追加
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent align="start" className="w-80 p-0">
                            <Command
                                shouldFilter={false}
                                label={`${assigneeField.name}の処理担当者の候補`}
                            >
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
                                        options={options}
                                        onSelect={(user) => {
                                            onChange([...picked, user]);
                                            setOpen(false);
                                            setQuery('');
                                        }}
                                    />
                                </CommandList>
                            </Command>
                        </PopoverContent>
                    </Popover>
                </div>
            </FormBlock>
            <p className="text-sm text-muted-foreground" aria-live="polite">
                {`選択中 ${picked.length} 人`}
                {statusNoteOf(assigneeField, picked.length)}
            </p>
        </section>
    );
}
