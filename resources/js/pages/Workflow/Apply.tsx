import { Head } from '@inertiajs/react';
import { useRef } from 'react';
import { ApplyApplicant } from '@/components/Workflow/ApplyApplicant';
import { ApplyAttachmentSurface } from '@/components/Workflow/ApplyAttachmentSurface';
import { ApplyCommentSurface } from '@/components/Workflow/ApplyCommentSurface';
import { ApplyContactSurface } from '@/components/Workflow/ApplyContactSurface';
import { ApplyFormActions } from '@/components/Workflow/ApplyFormActions';
import { ApplyHeading } from '@/components/Workflow/ApplyHeading';
import { ApplyInfoSurface } from '@/components/Workflow/ApplyInfoSurface';
import { ApplyRecordSurface } from '@/components/Workflow/ApplyRecordSurface';
import { ApplicationTargetConfirmDialog } from '@/components/Workflow/ApplicationTargetConfirmDialog';
import { ApplyUnshownErrors } from '@/components/Workflow/ApplyUnshownErrors';
import { useApplyCandidateSource } from '@/hooks/Workflow/use-apply-candidate-source';
import { useApplyForm } from '@/hooks/Workflow/use-apply-form';
import { useFocusFirstInvalid } from '@/hooks/use-focus-first-invalid';
import { breadcrumbsOf } from '@/lib/Workflow/applyRoutes';
import { applyViewOf } from '@/lib/Workflow/applyScreen';
import type { WorkflowApplyProps } from '@/types';

type Props = WorkflowApplyProps;

/**
 * 申請・一時保存・再申請(docs の 設計/ワークフロー/画面/申請と案件.md 3 節、ルートの深さ/画面.md 5 節、案件の運用/画面/案件の画面.md の 6.1 節)。
 * 面の並び = 申請情報(申請者の欄と注意の囲みを先頭に)→ 申請内容 → コメント → 添付ファイル → 連絡。
 */
export default function Apply(props: Props) {
    const apply = useApplyForm(props);
    const { form, errors } = apply;
    const formRef = useRef<HTMLFormElement>(null);
    const view = applyViewOf(props, form.data.record, form.data.name);
    const source = useApplyCandidateSource(props, form.data.applicant);

    useFocusFirstInvalid(formRef, apply.focusRequest);

    return (
        <>
            <Head title={view.heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <ApplyHeading heading={view.heading} line={view.headingLine} />
                <form
                    ref={formRef}
                    noValidate
                    onSubmit={(event) => event.preventDefault()}
                    aria-label={`${view.heading}の入力`}
                    className="flex flex-col gap-6"
                >
                    <ApplyUnshownErrors props={props} errors={errors} />
                    <ApplyInfoSurface
                        props={props}
                        form={form}
                        view={view}
                        onChangeBaseDate={apply.changeBaseDate}
                    >
                        <ApplyApplicant
                            applicants={props.applicants}
                            form={form}
                            onChange={apply.changeApplicant}
                        />
                    </ApplyInfoSurface>
                    <ApplyRecordSurface
                        props={props}
                        source={source}
                        form={form}
                        revealRequest={apply.focusRequest}
                        onChange={apply.changeRecord}
                    />
                    <ApplyCommentSurface
                        form={form}
                        max={props.commentMaxLength}
                    />
                    <ApplyAttachmentSurface props={props} apply={apply} />
                    <ApplyContactSurface form={form} />
                    <ApplyFormActions props={props} view={view} apply={apply} />
                </form>
            </div>
            <ApplicationTargetConfirmDialog
                changes={apply.applicationTarget.confirm?.changes ?? null}
                onCancel={apply.applicationTarget.cancel}
                onReflect={apply.applicationTarget.reflect}
            />
        </>
    );
}

Apply.layout = (props: Props) => ({ breadcrumbs: breadcrumbsOf(props) });
