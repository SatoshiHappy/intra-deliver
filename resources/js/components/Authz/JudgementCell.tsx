import { GridCell } from '@/components/Authz/GridCells';
import { ResultWord } from '@/components/Authz/ResultWord';
import { decidingNames } from '@/lib/Authz/gridView';
import { judgementTitle } from '@/lib/Authz/labels';
import type { AuthzGridJudgement } from '@/types';

/**
 * 「判定」タブのセル(行 × アクション)。結果の語と、その下に小さく効いた対象者の表示名(「、」でつなぐ。折り返すのは「、」の後だけで、名前の途中では折り返さない)。
 * 効いた対象者の無い禁止は語だけで、title に「該当する設定なし(既定の禁止)」。judgement が無い(= この種類のリソースを持たない行)は
 * 「—」と title。subjectNames = 対象者の ID → 表示名(列に無い ID は #<id>)。
 */
export function JudgementCell({
    judgement,
    subjectNames,
}: {
    judgement: AuthzGridJudgement | undefined;
    subjectNames: ReadonlyMap<number, string>;
}) {
    if (judgement === undefined) {
        return (
            <GridCell
                className="text-center text-muted-foreground"
                title="この種類のリソースがないため、判定しません"
            >
                —
            </GridCell>
        );
    }

    const names = decidingNames(
        judgement.decidingSubjectGroupIds,
        subjectNames,
    );

    return (
        <GridCell title={judgementTitle(judgement.result, names)}>
            <div className="flex flex-col items-center text-center leading-tight">
                <ResultWord result={judgement.result} />
                {names.length > 0 && (
                    <span className="text-xs whitespace-normal text-muted-foreground">
                        {names.map((name, index) => (
                            <span key={index} className="whitespace-nowrap">
                                {name}
                                {index < names.length - 1 && '、'}
                            </span>
                        ))}
                    </span>
                )}
            </div>
        </GridCell>
    );
}
