import { StepCard } from '@/components/Workflow/StepCard';
import { SubjectPartyField } from '@/components/Workflow/SubjectPartyField';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { CONFIRMERS_LINE } from '@/lib/Workflow/stepCardTexts';

/**
 * カード「確認する人」(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節): 札と検索の欄「確認する人を追加」
 * (参照者の欄と同じ部品。候補はユーザーだけ)。欄が 1 つなので欄の名前を出さない(設計/画面の意匠/README.md の方針 9(f))。
 * 誤りのキー = 並び全体は confirmers、札は confirmers.<添字>。number は呼び手が出すカードの並び(cardOrderOf)から振る。
 */
export function StepConfirmersCard({
    number,
    form,
}: {
    number: number;
    form: StepFormApi;
}) {
    return (
        <StepCard
            id="step-card-confirmers"
            number={number}
            heading="確認する人"
            help={CONFIRMERS_LINE}
        >
            <SubjectPartyField
                id="step-confirmers"
                label="確認する人"
                type="user"
                rows={form.values.confirmers}
                errors={form.errors}
                errorKey="confirmers"
                onChange={(rows) => form.set('confirmers', rows)}
            />
        </StepCard>
    );
}
