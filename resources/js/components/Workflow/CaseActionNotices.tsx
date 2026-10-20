import { Link, usePage } from '@inertiajs/react';
import { STALE_REVISION_MESSAGE } from '@/lib/Workflow/workflowLabels';

/**
 * ダイアログの上に出す知らせ(案件のエラー。改訂番号の食い違いなら再表示のリンクを添える)と、
 * 操作を拒む理由(blockedTitle = 見出し、blockedReason = 理由。理由が無ければ出さない)。
 */
export function CaseActionNotices({
    caseError,
    blockedTitle,
    blockedReason,
}: {
    caseError: string | undefined;
    blockedTitle: string | undefined;
    blockedReason: string | null;
}) {
    const { url } = usePage();

    return (
        <>
            {caseError !== undefined && (
                <p role="alert" className="text-sm text-destructive">
                    {caseError}
                    {caseError === STALE_REVISION_MESSAGE && (
                        <>
                            {' '}
                            <Link
                                href={url}
                                className="underline underline-offset-4"
                            >
                                再表示
                            </Link>
                        </>
                    )}
                </p>
            )}
            {blockedReason !== null && (
                <div
                    role="alert"
                    className="grid gap-1 text-sm text-destructive"
                >
                    <p className="font-semibold">{blockedTitle}</p>
                    <p>{blockedReason}</p>
                </div>
            )}
        </>
    );
}
