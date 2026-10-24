import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { confirm as confirmCase } from '@/routes/workflow/cases';

/**
 * 確認のボタン(ダイアログを持たず、押すと送る = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節)。見出しの右と確認の面の下に置く。
 * 失敗の文言(キー case)はボタンの下に出す。
 */
export function CaseConfirmButton({
    caseId,
    variant,
}: {
    caseId: number;
    variant: 'default' | 'outline';
}) {
    const form = useForm({});
    const caseError = (form.errors as Record<string, string | undefined>).case;

    return (
        <div className="flex flex-col gap-1">
            <Button
                type="button"
                variant={variant}
                disabled={form.processing}
                onClick={() =>
                    form.post(confirmCase.url(caseId), { preserveScroll: true })
                }
            >
                確認
            </Button>
            {caseError !== undefined && (
                <p role="alert" className="text-sm text-destructive">
                    {caseError}
                </p>
            )}
        </div>
    );
}
