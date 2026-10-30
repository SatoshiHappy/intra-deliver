import { showsApplicantField } from '@/lib/Workflow/applyApplicant';
import { unshownKeysOf } from '@/lib/Workflow/applyScreen';
import type { WorkflowApplyProps } from '@/types';

/**
 * 欄の下に出さない誤り(case・draft・record・封筒の誤り。申請者の欄が無いときは申請者の誤り、添付の面が無いときは添付の誤りも)を
 * フォームの先頭に出す。送った後に最初の誤りへフォーカスを移せるよう、tabIndex をいつも付ける。
 */
export function ApplyUnshownErrors({
    props,
    errors,
}: {
    props: Pick<WorkflowApplyProps, 'applicants' | 'attachment'>;
    errors: Record<string, string | undefined>;
}) {
    const keys = unshownKeysOf(
        errors,
        showsApplicantField(props.applicants),
        props.attachment,
    );

    return keys.map((key) => (
        <p
            key={key}
            role="alert"
            tabIndex={-1}
            className="text-sm text-destructive"
        >
            {errors[key]}
        </p>
    ));
}
