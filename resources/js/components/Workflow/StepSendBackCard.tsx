import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { StepCard } from '@/components/Workflow/StepCard';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { HELP, SEND_BACK_LINE } from '@/lib/Workflow/stepCardTexts';
import type { WorkflowNodeChoice } from '@/types';

const CARD_ID = 'step-card-send-back';

/**
 * 候補のステップの Checkbox の並び(候補の並びのまま。送る値も候補の並びにそろえる)。
 */
function SendBackChecks({
    candidates,
    form,
}: {
    candidates: WorkflowNodeChoice[];
    form: StepFormApi;
}) {
    const selected = form.values.sendBack;
    const toggle = (code: string, checked: boolean) =>
        form.set(
            'sendBack',
            candidates
                .map((candidate) => candidate.code)
                .filter((one) =>
                    one === code ? checked : selected.includes(one),
                ),
        );

    return (
        <div
            role="group"
            aria-labelledby={CARD_ID}
            className="flex flex-col gap-2"
        >
            {candidates.map((candidate) => (
                <div key={candidate.code} className="flex items-center gap-2">
                    <Checkbox
                        id={`step-send-back-${candidate.code}`}
                        checked={selected.includes(candidate.code)}
                        onCheckedChange={(checked) =>
                            toggle(candidate.code, checked === true)
                        }
                    />
                    <Label htmlFor={`step-send-back-${candidate.code}`}>
                        {candidate.name}
                    </Label>
                </div>
            ))}
        </div>
    );
}

/**
 * カード 3「差戻しできる先」: 祖先のステップの Checkbox の並び。「?」の説明は、カードの説明の文と 1 つも付けない場合の意味の 2 文。
 */
export function StepSendBackCard({
    candidates,
    form,
}: {
    candidates: WorkflowNodeChoice[];
    form: StepFormApi;
}) {
    const error = form.errors.send_back_node_codes;

    return (
        <StepCard
            id={CARD_ID}
            number={3}
            heading="差戻しできる先"
            help={`${SEND_BACK_LINE}${HELP.sendBack}`}
        >
            <SendBackChecks candidates={candidates} form={form} />
            {error !== undefined && (
                <p className="text-sm text-destructive">{error}</p>
            )}
        </StepCard>
    );
}
