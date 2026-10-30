import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { FormBlock, invalidProps } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import { Command, CommandInput, CommandList } from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { AssigneeCandidateList } from '@/components/Workflow/AssigneeCandidateList';
import { PickedUserList } from '@/components/Workflow/PickedUserList';
import { useUserCandidates } from '@/hooks/Workflow/use-user-candidates';
import { CANDIDATE_QUERY_MAX_LENGTH } from '@/hooks/use-record-candidates';
import { canAddUser } from '@/lib/Workflow/caseActionForm';
import type { PickedUser } from '@/lib/Workflow/caseActionForm';

type Props = {
    /** 検索の入力ボタンの id(ラベルの htmlFor。誤りの文言・説明の id もここから作る) */
    id: string;
    label: string;
    /** ラベルの横の「?」の説明(Tooltip) */
    help?: ReactNode;
    /** 入力の下の説明(「10 人まで」など) */
    hint?: string;
    /** ラベルの横に出すもの(「必須」の札など) */
    labelAddon?: ReactNode;
    error?: string;
    /** 候補の基準日にする案件(申請基準日。無ければ baseDate、それも無ければ今日) */
    caseId: number | null;
    /** 候補の基準日(YYYY-MM-DD。代理の登録の欄が入力中の開始日を渡す。案件があれば案件が先) */
    baseDate?: string;
    /** 指定されるステップ(承認のダイアログの指定の欄。候補は workflow.cases.assignee-candidates の口から引く。caseId が要る) */
    candidateNodeId?: number;
    picked: PickedUser[];
    /** 並びが処理の順(横配置の指定)。選んだ人の札に上へ・下への印を足す */
    isOrdered?: boolean;
    /** 選んだ人の並びの読み上げの名前(省くと「選んだ{ラベル}」) */
    listLabel?: string;
    /** 候補の一覧の読み上げの名前(省くと「{ラベル}の候補」。同じラベルの欄が並ぶダイアログは欄ごとに別の名前を渡す) */
    candidateLabel?: string;
    /** 選べる人数の上限(1 = 選び直すと置き換える) */
    max: number;
    onChange: (picked: PickedUser[]) => void;
};

/** 候補の欄が引く対象(候補の一覧の読み上げの名前・基準にする案件と日・候補から除く人) */
type CandidateScope = {
    candidateLabel: string;
    caseId: number | null;
    baseDate: string | undefined;
    nodeId: number | undefined;
    exclude: Set<number>;
};

/**
 * 検索の入力と候補の一覧(語を空にしたままなら候補を引かない。選んだ人は候補から除く)。
 */
function CandidateCommand({
    candidateLabel,
    caseId,
    baseDate,
    nodeId,
    open,
    exclude,
    query,
    onQueryChange,
    onSelect,
}: CandidateScope & {
    open: boolean;
    query: string;
    onQueryChange: (query: string) => void;
    onSelect: (user: PickedUser) => void;
}) {
    const candidates = useUserCandidates(
        caseId,
        open ? query : '',
        baseDate,
        nodeId,
    );
    const options = candidates.users?.filter((user) => !exclude.has(user.id));

    return (
        <Command shouldFilter={false} label={candidateLabel}>
            <CommandInput
                value={query}
                onValueChange={onQueryChange}
                maxLength={CANDIDATE_QUERY_MAX_LENGTH}
                placeholder="名前・コードで検索"
            />
            <CommandList>
                <AssigneeCandidateList
                    query={query}
                    candidates={candidates}
                    options={options}
                    onSelect={onSelect}
                />
            </CommandList>
        </Command>
    );
}

/**
 * 「ユーザーを検索して追加」のボタン(Popover の開閉の引き金。asChild で渡されるので ref と属性を受ける)。
 */
function SearchTrigger({
    id,
    isOpen,
    isDisabled,
    fieldProps,
    ...rest
}: {
    id: string;
    isOpen: boolean;
    isDisabled: boolean;
    fieldProps: ReturnType<typeof invalidProps>;
} & ComponentProps<'button'>) {
    return (
        <Button
            {...rest}
            id={id}
            type="button"
            variant="outline"
            className="w-fit"
            disabled={isDisabled}
            aria-label="ユーザーを検索して追加"
            aria-expanded={isOpen}
            {...fieldProps}
        >
            <Plus aria-hidden="true" />
            ユーザーを検索して追加
        </Button>
    );
}

/**
 * 「ユーザーを検索して追加」の Popover(開閉と検索語はこの部品が持つ)。
 */
function SearchPopover({
    id,
    fieldProps,
    isDisabled,
    onPick,
    ...command
}: CandidateScope & {
    id: string;
    fieldProps: ReturnType<typeof invalidProps>;
    isDisabled: boolean;
    onPick: (user: PickedUser) => void;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const close = () => {
        setOpen(false);
        setQuery('');
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => (next ? setOpen(true) : close())}
        >
            <PopoverTrigger asChild>
                <SearchTrigger
                    id={id}
                    isOpen={open}
                    isDisabled={isDisabled}
                    fieldProps={fieldProps}
                />
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-0">
                <CandidateCommand
                    {...command}
                    open={open}
                    query={query}
                    onQueryChange={setQuery}
                    onSelect={(user) => {
                        onPick(user);
                        close();
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}

/**
 * 人を選ぶ欄(処理のダイアログの連絡する人・担当変更先、申請の画面の連絡、代理の画面の代理先・代理元・絞り込みが使い回す。docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.2 節・
 * 1.3 節): 選んだ人の札の並びと「ユーザーを検索して追加」(Popover + Command。候補 = workflow.user-candidates)。
 * 上限に達したら追加できない(上限 1 の欄は選び直すと置き換える)。人数の上限を超える分は送った時の業務処理の 422 が正本。
 */
export function UserPickerField({
    id,
    label,
    help,
    hint,
    labelAddon,
    error,
    caseId,
    baseDate,
    candidateNodeId,
    picked,
    isOrdered,
    listLabel = `選んだ${label}`,
    candidateLabel = `${label}の候補`,
    max,
    onChange,
}: Props) {
    return (
        <FormBlock
            id={id}
            label={label}
            help={help}
            hint={hint}
            labelAddon={labelAddon}
            error={error}
        >
            <div className="grid gap-2">
                <PickedUserList
                    label={listLabel}
                    picked={picked}
                    isOrdered={isOrdered}
                    onChange={onChange}
                />
                <SearchPopover
                    id={id}
                    candidateLabel={candidateLabel}
                    fieldProps={invalidProps(id, error, help !== undefined)}
                    caseId={caseId}
                    baseDate={baseDate}
                    nodeId={candidateNodeId}
                    isDisabled={!canAddUser(picked.length, max)}
                    exclude={new Set(picked.map((person) => person.id))}
                    onPick={(user) =>
                        onChange(max === 1 ? [user] : [...picked, user])
                    }
                />
            </div>
        </FormBlock>
    );
}
