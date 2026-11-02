import { NameSegmentsText } from '@/components/Workflow/PersonMenu';
import type { useApplyForm } from '@/hooks/Workflow/use-apply-form';
import { NEXT_STALE_NOTE } from '@/lib/Workflow/applyReload';
import type { applyViewOf } from '@/lib/Workflow/applyScreen';
import type { NameSegment } from '@/lib/Workflow/nameSegments';
import { ClearedNotice } from '@/components/ClearedNotice';
import type { ApplyCleared } from '@/lib/Workflow/applyCleared';

/**
 * 申請の画面の見出しと、その下の 1 行(1 行の文は mode と props で決まる = applyScreen の headingLineOf。次の処理担当者の名前は札の「…」つき)。
 * staleNote = 1 行(次のステップの表示)の上の添え書き(申請者・申請基準日の読み直しの失敗の後)。notices = その後に並べる注意
 * (写す時に空にした欄の件数など。null は出さない = docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」)。
 */
function ApplyHeading({
    heading,
    line,
    staleNote = null,
    notices = [],
}: {
    heading: string;
    line: NameSegment[];
    staleNote?: string | null;
    notices?: (string | null)[];
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
            {notices.map(
                (notice) =>
                    notice !== null && (
                        <ClearedNotice key={notice}>{notice}</ClearedNotice>
                    ),
            )}
        </div>
    );
}

/**
 * 申請の画面の見出し(読み直しの失敗の添え書きと、空にした欄の件数の注意・引き継ぐ参照者を外した 1 行を足したもの = docs の
 * 設計/ワークフロー/申請の周辺/画面.md の 5 節・8 節)。
 */
export function ApplyFormHeading({
    view,
    apply,
    cleared,
}: {
    view: ReturnType<typeof applyViewOf>;
    apply: ReturnType<typeof useApplyForm>;
    cleared: ApplyCleared;
}) {
    return (
        <ApplyHeading
            heading={view.heading}
            line={view.headingLine}
            staleNote={apply.isNextStale ? NEXT_STALE_NOTE : null}
            notices={[cleared.notice, cleared.viewersNotice]}
        />
    );
}
