import { useMemo } from 'react';
import type { RecordCandidateSource } from '@/hooks/use-record-candidates';
import { recordCandidatesUrlOf } from '@/lib/Workflow/applyRoutes';
import type { WorkflowApplyProps } from '@/types';

/**
 * 申請の画面の参照項目の候補の引き先 workflow.record-candidates(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。
 * クエリ = 再申請は case、ほかは flow(既存のデータからは record も)、applicant = 申請者が代理元ならその人。申請者を変えると
 * 引き先が替わり、開いている候補の欄は引き直す。
 */
export function useApplyCandidateSource(
    { mode, flow, case: workflowCase, recordId }: WorkflowApplyProps,
    applicant: number | null,
): RecordCandidateSource {
    const flowCode = flow.code;
    const caseId = workflowCase?.id ?? null;

    return useMemo(
        () => (moduleCode, query) =>
            recordCandidatesUrlOf(
                moduleCode,
                query,
                { mode, flowCode, caseId, recordId },
                applicant,
            ),
        [mode, flowCode, caseId, recordId, applicant],
    );
}
