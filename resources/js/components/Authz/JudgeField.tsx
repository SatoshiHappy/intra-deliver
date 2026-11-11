import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useOverviewVisit } from '@/hooks/Authz/useOverviewVisit';
import { nameOrCode } from '@/lib/nameOrCode';
import type { AuthzJudge, AuthzJudgeUser } from '@/types';

/** 判定する人の選択でゲストを表す値(クエリ user の値) */
const GUEST = 'guest';

const JUDGE_ID = 'authz-judge';

type Props = {
    candidates: AuthzJudgeUser[];
    judge: AuthzJudge;
};

/**
 * 選択の 3 行目「この人として判定」(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.4)。選択の横に判定した人の主所属。
 */
export function JudgeField({ candidates, judge }: Props) {
    const { chooseUser } = useOverviewVisit();

    return (
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
    );
}
