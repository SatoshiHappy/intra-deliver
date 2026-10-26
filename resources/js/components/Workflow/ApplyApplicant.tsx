import { usePage } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { FormBlock, invalidProps } from '@/components/FormBlock';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CaseActionCallout } from '@/components/Workflow/CaseActionNotices';
import {
    APPLICANT_HELP,
    applicantChoiceOf,
    applicantNoteOf,
    applicantOfValue,
    applicantValueOf,
    showsApplicantField,
} from '@/lib/Workflow/applyApplicant';
import type { ApplyForm } from '@/lib/Workflow/applyForm';
import type { WorkflowApplicant } from '@/types';

const APPLICANT_ID = 'workflow-apply-applicant';

/**
 * 選択肢の札(名前・所属と、「自分」「代理元」の語)。
 */
function ApplicantChoice({ choice }: { choice: WorkflowApplicant }) {
    const { name, department, word } = applicantChoiceOf(choice);

    return (
        <>
            <span>{name}</span>
            {department !== null && (
                <span className="text-xs text-muted-foreground">
                    {department}
                </span>
            )}
            <Badge variant="outline">{word}</Badge>
        </>
    );
}

/**
 * 「申請者」の欄(申請者の選択肢が 2 人以上のときだけ呼ぶ)。value = 選んだ申請者(代理元の id。自分なら null)。
 */
function ApplicantSelect({
    applicants,
    applicant,
    error,
    onChange,
}: {
    applicants: WorkflowApplicant[];
    applicant: number | null;
    error: string | undefined;
    onChange: (applicant: number | null) => void;
}) {
    return (
        <FormBlock
            id={APPLICANT_ID}
            label="申請者"
            labelAddon={<Badge variant="secondary">必須</Badge>}
            help={APPLICANT_HELP}
            error={error}
        >
            <Select
                value={applicantValueOf(applicants, applicant)}
                onValueChange={(value) =>
                    onChange(applicantOfValue(applicants, value))
                }
            >
                <SelectTrigger
                    id={APPLICANT_ID}
                    className="w-full"
                    {...invalidProps(APPLICANT_ID, error, true)}
                >
                    <SelectValue placeholder="選択してください" />
                </SelectTrigger>
                <SelectContent>
                    {applicants.map((choice) => (
                        <SelectItem key={choice.id} value={String(choice.id)}>
                            <ApplicantChoice choice={choice} />
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </FormBlock>
    );
}

/**
 * 申請情報の面の先頭: 「申請者」の欄(2 人以上のときだけ)と、申請者が代理元のときの注意の囲み(欄を出さない代理元 1 人・
 * 代理元の一時保存・代理元の案件の再申請も囲みだけ出す。docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。
 */
export function ApplyApplicant({
    applicants,
    form,
    onChange,
}: {
    applicants: WorkflowApplicant[];
    form: InertiaFormProps<ApplyForm>;
    onChange: (applicant: number | null) => void;
}) {
    const { account } = usePage().props;
    const selfName = account.user?.name ?? account.user?.code ?? '';
    const { applicant } = form.data;
    const errors = form.errors as Record<string, string | undefined>;
    const note = applicantNoteOf(applicants, applicant, selfName);

    return (
        <>
            {showsApplicantField(applicants) && (
                <ApplicantSelect
                    applicants={applicants}
                    applicant={applicant}
                    error={errors.applicant_user_id ?? errors.principal_user_id}
                    onChange={onChange}
                />
            )}
            {note !== null && (
                <CaseActionCallout title={note.title} text={note.text} />
            )}
        </>
    );
}
