/**
 * 申請の画面の見出しと、その下の 1 行(1 行の文は mode と props で決まる = applyScreen の headingLineOf)。
 */
export function ApplyHeading({
    heading,
    line,
}: {
    heading: string;
    line: string;
}) {
    return (
        <div className="flex flex-col gap-1">
            <h1 className="text-2xl font-semibold wrap-anywhere">{heading}</h1>
            <p className="text-sm text-muted-foreground">{line}</p>
        </div>
    );
}
