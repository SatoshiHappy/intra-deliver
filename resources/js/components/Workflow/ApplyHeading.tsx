import { NameSegmentsText } from '@/components/Workflow/PersonMenu';
import type { useApplyForm } from '@/hooks/Workflow/use-apply-form';
import { NEXT_STALE_NOTE } from '@/lib/Workflow/applyReload';
import type { applyViewOf } from '@/lib/Workflow/applyScreen';
import type { NameSegment } from '@/lib/Workflow/nameSegments';
import { clearedFieldsLineOf } from '@/lib/clearedFields';

/**
 * 申請の画面の見出しと、その下の 1 行(1 行の文は mode と props で決まる = applyScreen の headingLineOf。次の処理担当者の名前は札の「…」つき)。
 * staleNote = 1 行(次のステップの表示)の上の添え書き(申請者・申請基準日の読み直しの失敗の後)。notes = その後に並べる行
 * (写す時に空にした項目の 1 行など。null は出さない = docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節・8 節)。
 */
function ApplyHeading({
    heading,
    line,
    staleNote = null,
    notes = [],
}: {
    heading: string;
    line: NameSegment[];
    staleNote?: string | null;
    notes?: (string | null)[];
}) {
    return (
        <div className="flex flex-col gap-1">
            <h1 className="text-2xl font-semibold wrap-anywhere">{heading}</h1>
            {staleNote !== null && (
                <p className="text-sm text-muted-foreground">{staleNote}</p>
            )}
            <p className="text-sm text-muted-foreground">
                <NameSegmentsText segments={line} />
            </p>
            {notes.map(
                (note) =>
                    note !== null && (
                        <p key={note} className="text-sm text-muted-foreground">
                            {note}
                        </p>
                    ),
            )}
        </div>
    );
}

/**
 * 申請の画面の見出し(読み直しの失敗の添え書きと、申請対象の値の 1 行を足したもの = docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節・8 節)。
 */
export function ApplyFormHeading({
    view,
    apply,
}: {
    view: ReturnType<typeof applyViewOf>;
    apply: ReturnType<typeof useApplyForm>;
}) {
    return (
        <ApplyHeading
            heading={view.heading}
            line={view.headingLine}
            staleNote={apply.isNextStale ? NEXT_STALE_NOTE : null}
            notes={[
                clearedFieldsLineOf(
                    '申請対象',
                    apply.applicationTarget.cleared,
                ),
            ]}
        />
    );
}
