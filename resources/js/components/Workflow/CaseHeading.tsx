import { Link } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { CaseStateBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { headingStepOf } from '@/lib/Workflow/caseHeading';
import { copyRouteOf } from '@/lib/Workflow/caseCopy';
import type { WorkflowCaseShowProps } from '@/types';

type Props = Pick<WorkflowCaseShowProps, 'case' | 'proxyNote' | 'canCopy'>;

/**
 * 申請の詳細の見出し(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 1 節)。札は見出し(h1)の外に置き、
 * 見出しの読み上げの名前を申請名のままにする。右は元を変えない操作「複製」だけ(処理のボタンは画面の下の帯 = CaseActionBar)。
 */
export function CaseHeading({ proxyNote, canCopy, ...show }: Props) {
    const workflowCase = show.case;
    const step = headingStepOf(workflowCase);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div className="flex min-w-0 flex-[1_1_20rem] flex-wrap items-center gap-x-3 gap-y-1">
                    <h1 className="text-2xl font-semibold wrap-anywhere">
                        {workflowCase.name}
                    </h1>
                    <span className="flex items-center gap-1 text-sm">
                        <CaseStateBadge
                            state={workflowCase.state}
                            completionReason={workflowCase.completionReason}
                            currentNodeState={
                                workflowCase.currentSummary?.state ?? null
                            }
                        />
                        {step !== null && (
                            <span className="text-muted-foreground">
                                {step}
                            </span>
                        )}
                    </span>
                </div>
                {canCopy && (
                    <Button variant="outline" className="ml-auto" asChild>
                        <Link href={copyRouteOf(workflowCase)}>
                            <Copy aria-hidden="true" />
                            複製
                        </Link>
                    </Button>
                )}
            </div>
            {proxyNote !== null && (
                <p className="text-sm text-muted-foreground">{proxyNote}</p>
            )}
        </div>
    );
}
