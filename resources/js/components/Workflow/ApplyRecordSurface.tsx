import type { InertiaFormProps } from '@inertiajs/react';
import { FormSurface } from '@/components/FormLayout';
import { RecordFields } from '@/components/RecordFields';
import { RecordCandidateSourceContext } from '@/hooks/use-record-candidates';
import type { RecordCandidateSource } from '@/hooks/use-record-candidates';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import { clearRowErrors } from '@/lib/formErrors';
import type { RecordFormData } from '@/lib/recordForm';
import type { WorkflowApplyProps } from '@/types';

/**
 * 申請内容の面(業務データの欄は {@link RecordFields}。登録・編集のフォームと共通)。参照項目の候補は source の口から引く
 * (申請の画面は workflow.record-candidates。docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。
 */
export function ApplyRecordSurface({
    props,
    source,
    form,
    revealRequest,
    onChange,
}: {
    props: WorkflowApplyProps;
    source: RecordCandidateSource;
    form: InertiaFormProps<ApplyForm>;
    revealRequest: number;
    onChange: (update: (record: RecordFormData) => RecordFormData) => void;
}) {
    const { module, headings, recordId } = props;

    return (
        <FormSurface aria-labelledby="apply-record-heading">
            <h2 id="apply-record-heading" className="text-[15px] font-semibold">
                {module.name}
            </h2>
            <RecordCandidateSourceContext value={source}>
                <RecordFields
                    module={module}
                    headings={headings}
                    revealRequest={revealRequest}
                    recordId={recordId}
                    data={form.data.record}
                    onChange={onChange}
                    onRowsReordered={(prefix) => clearRowErrors(form, prefix)}
                    errors={form.errors as Record<string, string | undefined>}
                />
            </RecordCandidateSourceContext>
        </FormSurface>
    );
}
