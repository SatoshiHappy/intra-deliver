import { StepAssigneeRange } from '@/components/Workflow/StepAssigneeRange';
import { StepChoiceSelect } from '@/components/Workflow/StepChoiceSelect';
import { StepChoiceToggle } from '@/components/Workflow/StepChoiceToggle';
import { SubjectPartyField } from '@/components/Workflow/SubjectPartyField';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { BASE_LABELS, HELP, SCOPE_LABELS } from '@/lib/Workflow/stepCardTexts';
import type {
    WorkflowAdminFlowShowProps,
    WorkflowRelativeBase,
    WorkflowRelativeScope,
} from '@/types';

/** 列挙の語の表を選択肢の並びにする */
function choicesOf<T extends string>(labels: Record<T, string>) {
    return (Object.keys(labels) as T[]).map((value) => ({
        value,
        label: labels[value],
    }));
}

/**
 * 対象者で指定: 対象者の欄(札と、1 つの候補の一覧から 4 種類を選ぶ検索の欄。参照者の面と同じ部品)。
 */
export function FixedFields({ form }: { form: StepFormApi }) {
    return (
        <SubjectPartyField
            id="step-target-subjects"
            label="対象者"
            rows={form.values.rows}
            errors={form.errors}
            errorKey="targets"
            onChange={(rows) => form.set('rows', rows)}
        />
    );
}

/**
 * 基準にする人で指定: 基準にする人(切り替えボタン。申請者・前の承認者)・組織(所属組織・上位組織・上位組織全て)・役職(任意。指定なしに戻せる)。
 */
export function RelativeFields({
    form,
    companyPosts,
}: {
    form: StepFormApi;
    companyPosts: WorkflowAdminFlowShowProps['options']['companyPosts'];
}) {
    const { values, errors, set } = form;

    return (
        <>
            <StepChoiceToggle<WorkflowRelativeBase>
                id="step-relative-base"
                label="基準にする人"
                help={HELP.base}
                value={values.relativeBase}
                choices={choicesOf(BASE_LABELS)}
                error={errors.relative_base}
                onChange={(value) => set('relativeBase', value)}
            />
            <StepChoiceSelect<WorkflowRelativeScope>
                id="step-relative-scope"
                label="組織"
                help={HELP.scope}
                value={values.relativeScope}
                choices={choicesOf(SCOPE_LABELS)}
                error={errors.relative_scope}
                onChange={(value) => set('relativeScope', value)}
            />
            <StepChoiceSelect
                id="step-company-post"
                label="役職"
                help={HELP.post}
                noneLabel="指定なし"
                value={values.companyPostId}
                choices={companyPosts.map((post) => ({
                    value: String(post.id),
                    label: post.name,
                }))}
                error={errors.company_post_id}
                onChange={(value) => set('companyPostId', value)}
            />
        </>
    );
}

/**
 * 処理の時に指定: 選択する人(指定するステップ)と、hasRange なら人数の最小・最大(縦配置はカード 4 に出す)。
 */
export function DesignatedFields({
    form,
    designators,
    hasRange,
}: {
    form: StepFormApi;
    designators: WorkflowAdminFlowShowProps['options']['designators'];
    hasRange: boolean;
}) {
    return (
        <>
            <StepChoiceSelect
                id="step-designator"
                label="選択する人"
                value={form.values.designatorCode}
                choices={designators.map((one) => ({
                    value: one.code,
                    label: one.name,
                }))}
                error={form.errors.designator_node_code}
                onChange={(value) => form.set('designatorCode', value)}
            />
            {hasRange && (
                <StepAssigneeRange idPrefix="step-designated" form={form} />
            )}
        </>
    );
}
