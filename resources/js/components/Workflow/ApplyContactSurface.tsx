import type { InertiaFormProps } from '@inertiajs/react';
import { FormSurface } from '@/components/FormLayout';
import { HeadingHelp } from '@/components/HeadingHelp';
import { CaseActionContactField } from '@/components/Workflow/CaseActionContactField';
import type { ApplyForm } from '@/lib/Workflow/applyForm';

/**
 * 連絡の面(申請・一時保存からの申請・再申請の画面。docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節): 見出し「連絡」(横の「?」に説明)、
 * 欄は処理のダイアログの連絡と同じ(連絡する人は 10 人まで、ひと言は 1000 文字まで)。一時保存の操作では送らない。
 */
export function ApplyContactSurface({
    form,
}: {
    form: InertiaFormProps<ApplyForm>;
}) {
    return (
        <FormSurface aria-labelledby="apply-contact-heading">
            <div className="flex items-center gap-1.5">
                <h2
                    id="apply-contact-heading"
                    className="text-[15px] font-semibold"
                >
                    連絡
                </h2>
                <HeadingHelp label="連絡">
                    選択した人へ、ひと言を添えた通知を送ります。履歴には残らず、申請を閲覧できる人は変わりません。
                </HeadingHelp>
            </div>
            <CaseActionContactField
                users={form.data.contact_users}
                message={form.data.contact_message}
                errors={form.errors}
                onUsersChange={(users) => form.setData('contact_users', users)}
                onMessageChange={(message) =>
                    form.setData('contact_message', message)
                }
            />
        </FormSurface>
    );
}
