import { CaseActionBar } from '@/components/Workflow/CaseActionBar';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { CaseStateBadge } from '@/components/Workflow/WorkflowStatusBadge';
import { headingStepOf } from '@/lib/Workflow/caseHeading';
import type { WorkflowCaseShowProps } from '@/types';

type Props = Pick<
    WorkflowCaseShowProps,
    | 'case'
    | 'can'
    | 'nodeActions'
    | 'pullBackNodes'
    | 'casePrincipal'
    | 'commentMaxLength'
    | 'proxyNote'
> & { onOpen: OpenCaseAction };

/**
 * 申請の詳細の見出し(設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 1 節)。札は見出し(h1)の外に置き、
 * 見出しの読み上げの名前を申請名のままにする。
 */
export function CaseHeading({ proxyNote, onOpen, ...show }: Props) {
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
                <CaseActionBar {...show} onOpen={onOpen} />
            </div>
            {proxyNote !== null && (
                <p className="text-sm text-muted-foreground">{proxyNote}</p>
            )}
        </div>
    );
}
