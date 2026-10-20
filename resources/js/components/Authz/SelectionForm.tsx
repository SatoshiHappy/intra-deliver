import { FieldHelp, invalidProps } from '@/components/FormBlock';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useOverviewVisit } from '@/hooks/Authz/useOverviewVisit';
import { actionLabel, resourceTypeLabel } from '@/lib/Authz/labels';
import { nameOrCode } from '@/lib/nameOrCode';
import type { AuthzGrid, AuthzJudge, AuthzJudgeUser } from '@/types';

/** 判定する人の選択でゲストを表す値(クエリ user の値) */
const GUEST = 'guest';

const TYPE_ID = 'authz-type';
const JUDGE_ID = 'authz-judge';

type Props = {
    resourceTypes: string[];
    grid: AuthzGrid;
    candidates: AuthzJudgeUser[];
    judge: AuthzJudge;
};

/**
 * 選択の 2 行(ラベルは左で幅を揃える。docs の 認可設定の画面.md 2.3)。
 * 1 行目 = リソースの種類。アクションが 1 つの種類は、ラベルの横の「?」(FieldHelp)にそのアクションを書く(アクションの段を出さないため)。
 * 2 行目 = この人として判定(選択の横に判定した人の主所属)。
 */
export function SelectionForm({
    resourceTypes,
    grid,
    candidates,
    judge,
}: Props) {
    const { chooseType, chooseUser } = useOverviewVisit();
    const onlyAction = grid.actions.length === 1 ? grid.actions[0] : undefined;

    return (
        <div className="flex flex-none flex-col gap-2.5">
            <div className="flex min-h-9 items-center gap-2.5">
                <div className="flex w-32 flex-none items-center gap-1">
                    <Label htmlFor={TYPE_ID}>リソースの種類</Label>
                    {onlyAction !== undefined && (
                        <FieldHelp id={TYPE_ID} label="リソースの種類">
                            {`${resourceTypeLabel(grid.resourceType)}のアクションは「${actionLabel(onlyAction)}」の 1 つです`}
                        </FieldHelp>
                    )}
                </div>
                <Select value={grid.resourceType} onValueChange={chooseType}>
                    <SelectTrigger
                        id={TYPE_ID}
                        className="min-w-48"
                        {...(onlyAction === undefined
                            ? {}
                            : invalidProps(TYPE_ID, undefined, true))}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {resourceTypes.map((resourceType) => (
                            <SelectItem key={resourceType} value={resourceType}>
                                {resourceTypeLabel(resourceType)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="flex min-h-9 items-center gap-2.5">
                <div className="w-32 flex-none">
                    <Label htmlFor={JUDGE_ID}>この人として判定</Label>
                </div>
                <Select
                    value={judge.user === null ? GUEST : String(judge.user.id)}
                    onValueChange={chooseUser}
                >
                    <SelectTrigger id={JUDGE_ID} className="min-w-48">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={GUEST}>
                            ゲスト(ログインしていない状態)
                        </SelectItem>
                        {candidates.map((candidate) => (
                            <SelectItem
                                key={candidate.id}
                                value={String(candidate.id)}
                            >
                                {nameOrCode(candidate)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <span className="text-sm whitespace-nowrap text-muted-foreground">
                    {judge.department === null
                        ? '主所属なし'
                        : nameOrCode(judge.department)}
                </span>
            </div>
        </div>
    );
}
