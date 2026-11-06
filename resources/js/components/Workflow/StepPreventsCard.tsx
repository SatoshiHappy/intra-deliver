import { Checkbox } from '@/components/ui/checkbox';
import { StepCard } from '@/components/Workflow/StepCard';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { preventsLine } from '@/lib/Workflow/stepCardTexts';
import type { WorkflowNodeKind } from '@/types';

const CARD_ID = 'step-card-prevents';

/**
 * カード「申請者本人の承認を防ぐ」: オン・オフ(Checkbox。見出しの下に置く。「?」の説明は、縦配置は合議の承認者に選択できない文)。
 * number は呼び手が出すカードの並び(cardOrderOf)から振る。
 */
export function StepPreventsCard({
    number,
    kind,
    form,
}: {
    number: number;
    kind: WorkflowNodeKind;
    form: StepFormApi;
}) {
    const { values, errors, set } = form;
    const error = errors.prevents_applicant_approval;

    return (
        <StepCard
            id={CARD_ID}
            number={number}
            heading="申請者本人の承認を防ぐ"
            help={preventsLine(kind)}
        >
            <span className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={values.prevents}
                    aria-labelledby={CARD_ID}
                    aria-invalid={error === undefined ? undefined : true}
                    onCheckedChange={(checked) =>
                        set('prevents', checked === true)
                    }
                />
                {values.prevents ? 'オン' : 'オフ'}
            </span>
            {error !== undefined && (
                <p className="text-sm text-destructive">{error}</p>
            )}
        </StepCard>
    );
}
