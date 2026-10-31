import { NameSegmentsText } from '@/components/Workflow/PersonMenu';
import type { NameSegment } from '@/lib/Workflow/nameSegments';

/**
 * 申請の画面の見出しと、その下の 1 行(1 行の文は mode と props で決まる = applyScreen の headingLineOf。次の処理担当者の名前は札の「…」つき)。
 */
export function ApplyHeading({
    heading,
    line,
}: {
    heading: string;
    line: NameSegment[];
}) {
    return (
        <div className="flex flex-col gap-1">
            <h1 className="text-2xl font-semibold wrap-anywhere">{heading}</h1>
            <p className="text-sm text-muted-foreground">
                <NameSegmentsText segments={line} />
            </p>
        </div>
    );
}
