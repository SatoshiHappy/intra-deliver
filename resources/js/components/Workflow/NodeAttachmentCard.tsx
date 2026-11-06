import { formErrorId } from '@/components/FormBlock';
import { BooleanChoiceRadios } from '@/components/Workflow/BooleanChoiceRadios';
import { StepCard } from '@/components/Workflow/StepCard';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { ATTACHMENT_ADD_CHOICES } from '@/lib/Workflow/booleanChoice';
import { ATTACHMENT_LINE } from '@/lib/Workflow/stepCardTexts';

const CARD_ID = 'step-card-attachment';
const FIELD_ID = 'step-attachment-prevents';

/**
 * カード「添付ファイル」(設計/ワークフロー/申請の周辺/画面.md の 4 節): ラジオ「追加できる」「追加できない」。
 * number は呼び手が出すカードの並び(cardOrderOf)から振る。
 */
export function NodeAttachmentCard({
    number,
    form,
}: {
    number: number;
    form: StepFormApi;
}) {
    const { values, errors, set } = form;
    const error = errors.prevents_attachment_add;

    return (
        <StepCard
            id={CARD_ID}
            number={number}
            heading="添付ファイル"
            help={ATTACHMENT_LINE}
        >
            <BooleanChoiceRadios
                id={FIELD_ID}
                labelledBy={CARD_ID}
                value={values.preventsAttachmentAdd}
                choices={ATTACHMENT_ADD_CHOICES}
                error={error}
                onChange={(value) => set('preventsAttachmentAdd', value)}
            />
            {error !== undefined && (
                <p
                    id={formErrorId(FIELD_ID)}
                    className="text-sm text-destructive"
                >
                    {error}
                </p>
            )}
        </StepCard>
    );
}
