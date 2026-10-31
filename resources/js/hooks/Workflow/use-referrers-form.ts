import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { clearRowErrors } from '@/lib/formErrors';
import { draftsOf, rowsPayloadOf } from '@/lib/Workflow/subjectRows';
import type { SubjectRowDraft } from '@/lib/Workflow/subjectRows';
import { update } from '@/routes/workflow/admin/flows/referrers';
import type { WorkflowSubjectRow } from '@/types';

/** 行の誤りのキー(並び全体は referrers、行は referrers.<添字>) */
export const REFERRERS_ERROR_KEY = 'referrers';

/**
 * 参照者のタブの入力と送信(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.4 節)。
 * 保存はすべての行を並びのまま 1 回で送る(0 行 = 参照者なし)。取消は今の値(サーバーの行)に戻す。
 * 並びが変わると添字の誤りは別の行に出てしまうので、行を変えたら行の誤りを消す。
 */
export function useReferrersForm(flow: string, saved: WorkflowSubjectRow[]) {
    const form = useForm<{ referrers: SubjectRowDraft[] }>({
        referrers: draftsOf(saved),
    });

    return {
        rows: form.data.referrers,
        errors: form.errors as Record<string, string | undefined>,
        isProcessing: form.processing,
        change: (rows: SubjectRowDraft[]) => {
            clearRowErrors(form, REFERRERS_ERROR_KEY);
            form.setData('referrers', rows);
        },
        cancel: () => {
            form.clearErrors();
            form.setData('referrers', draftsOf(saved));
        },
        submit: (event: FormEvent) => {
            event.preventDefault();
            form.transform((data) => ({
                referrers: rowsPayloadOf(data.referrers),
            }));
            form.submit(update(flow), { preserveScroll: true });
        },
    };
}
