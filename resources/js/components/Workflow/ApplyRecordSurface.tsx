import { FormSurface } from '@/components/FormLayout';
import { RecordFields } from '@/components/RecordFields';
import { RecordCandidateSourceContext } from '@/hooks/use-record-candidates';
import type { RecordCandidateSource } from '@/hooks/use-record-candidates';
import type { useApplyForm } from '@/hooks/Workflow/use-apply-form';
import type { FieldMarks } from '@/lib/clearedFields';
import { clearRowErrors } from '@/lib/formErrors';

/**
 * 申請内容の面(業務データの欄は {@link RecordFields}。登録・編集のフォームと共通)。参照項目の候補は source の口から引く
 * (申請の画面は workflow.record-candidates。docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。
 */
export function ApplyRecordSurface({
    apply,
    source,
    marks,
}: {
    apply: ReturnType<typeof useApplyForm>;
    source: RecordCandidateSource;
    /** 今は使えない値を空にした欄の印 */
    marks: FieldMarks;
}) {
    const { form } = apply;
    const { module, headings, recordId } = apply.props;

    return (
        <FormSurface aria-labelledby="apply-record-heading">
            <h2 id="apply-record-heading" className="text-[15px] font-semibold">
                {module.name}
            </h2>
            <RecordCandidateSourceContext value={source}>
                <RecordFields
                    module={module}
                    headings={headings}
                    revealRequest={apply.focusRequest}
                    recordId={recordId}
                    data={form.data.record}
                    onChange={apply.changeRecord}
                    onRowsReordered={(prefix) => clearRowErrors(form, prefix)}
                    errors={form.errors as Record<string, string | undefined>}
                    marks={marks}
                />
            </RecordCandidateSourceContext>
        </FormSurface>
    );
}
