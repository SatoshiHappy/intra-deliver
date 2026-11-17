import { StepCard } from '@/components/Workflow/StepCard';
import { StepNumberField } from '@/components/Workflow/StepNumberField';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { DEADLINE_LINE } from '@/lib/Workflow/stepCardTexts';

const CARD_ID = 'step-card-deadline';
const FIELD_ID = 'step-deadline-days';

/**
 * カード「処理期限」(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節): 数の入力と「日」。欄が 1 つなので
 * 欄の名前を出さず、入力の読み上げの名前は見出し(設計/画面の意匠/README.md の方針 9(f))。空欄は期限なし。
 * 数の欄の持ち方は StepNumberField(docs の 設計/画面の意匠/README.md の方針 9 の (g))。number は呼び手が出すカードの並び(cardOrderOf)から振る。
 */
export function StepDeadlineCard({
    number,
    form,
}: {
    number: number;
    form: StepFormApi;
}) {
    const { values, errors, set } = form;

    return (
        <StepCard
            id={CARD_ID}
            number={number}
            heading="処理期限"
            help={DEADLINE_LINE}
        >
            <StepNumberField
                id={FIELD_ID}
                labelledBy={CARD_ID}
                unit="日"
                value={values.deadline}
                error={errors.deadline_days}
                onChange={(value) => set('deadline', value)}
            />
        </StepCard>
    );
}
