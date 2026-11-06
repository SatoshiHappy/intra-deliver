import { formErrorId, invalidProps } from '@/components/FormBlock';
import { Input } from '@/components/ui/input';
import { StepCard } from '@/components/Workflow/StepCard';
import type { StepFormApi } from '@/hooks/Workflow/use-step-form';
import { DEADLINE_LINE } from '@/lib/Workflow/stepCardTexts';

const CARD_ID = 'step-card-deadline';
const FIELD_ID = 'step-deadline-days';

/**
 * カード「処理期限」(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節): 数の入力と「日」。欄が 1 つなので
 * 欄の名前を出さず、入力の読み上げの名前は見出し(設計/画面の意匠/README.md の方針 9(f))。空欄は期限なし。
 * 打った文字のまま持ち、範囲の誤りはサーバーが欄の下に返す(type="number" はブラウザが数と読めない文字を空にし、空欄 = 期限なしとして
 * 黙って保存されるので使わない)。number は呼び手が出すカードの並び(cardOrderOf)から振る。
 */
export function StepDeadlineCard({
    number,
    form,
}: {
    number: number;
    form: StepFormApi;
}) {
    const { values, errors, set } = form;
    const error = errors.deadline_days;

    return (
        <StepCard
            id={CARD_ID}
            number={number}
            heading="処理期限"
            help={DEADLINE_LINE}
        >
            <div className="flex items-center gap-2 text-sm">
                <Input
                    id={FIELD_ID}
                    inputMode="numeric"
                    className="w-16"
                    value={values.deadline}
                    aria-labelledby={CARD_ID}
                    onChange={(event) => set('deadline', event.target.value)}
                    {...invalidProps(FIELD_ID, error)}
                />
                <span>日</span>
            </div>
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
