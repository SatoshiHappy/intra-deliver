import { router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { RecordFormData } from '@/lib/recordForm';
import { createFormData, editFormData, toPayload } from '@/lib/recordForm';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/** フォームの値(業務データの欄は業務データの入れ物の登録・編集のフォームと同じ形。base_date = 申請基準日) */
export type ApplyForm = {
    name: string;
    comment: string;
    base_date: string;
    record: RecordFormData;
};

/**
 * 申請・一時保存・再申請のフォームの状態と送信(docs の 設計/ワークフロー/画面/申請と案件.md 3 節)。
 * focusRequest は送信の失敗ごとに増え、呼ぶ側が誤りのある欄へフォーカスを移す合図に使う。
 */
export function useApplyForm({
    mode,
    case: workflowCase,
    module,
    initial,
    baseDate,
}: WorkflowApplyProps) {
    const [initialData] = useState<ApplyForm>(() => ({
        name: initial?.name ?? '',
        comment: initial?.comment ?? '',
        base_date: baseDate,
        record:
            initial === null
                ? createFormData(module)
                : editFormData(module, initial),
    }));
    const form = useForm<ApplyForm>(initialData);
    const errors = form.errors as Record<string, string | undefined>;
    const cancelReload = useRef<(() => void) | null>(null);
    const [focusRequest, setFocusRequest] = useState(0);

    /** 申請基準日は申請・一時保存・一時保存からの申請で送る(再申請は申請基準日を変えない = 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.1) */
    const send = (action: RouteDefinition<'post' | 'put'>) => {
        form.transform((data) => ({
            name: data.name,
            comment: data.comment,
            ...toPayload(module, data.record),
            ...(workflowCase === null
                ? {}
                : { revision: workflowCase.revision }),
            ...(mode === 'reapply' ? {} : { base_date: data.base_date }),
        }));
        form.submit(action, {
            onError: () => setFocusRequest((count) => count + 1),
        });
    };

    /**
     * 申請基準日を変えたら、入力中の値を保ったまま next だけを引き直す(部分再読み込み = ルートの深さ/画面.md 5 節)。送る直前に前の要求を取り消し、
     * 最後に送った日付の応答だけを残す(古い応答が後から届いて欄と食い違わないように)。戻るで食い違わないよう履歴は積まない(replace)。
     */
    const changeBaseDate = (value: string) => {
        form.setData('base_date', value);
        cancelReload.current?.();
        cancelReload.current = null;

        if (value !== '') {
            router.reload({
                data: { base_date: value },
                only: ['next'],
                replace: true,
                onCancelToken: (token) => {
                    cancelReload.current = token.cancel;
                },
            });
        }
    };

    return { form, errors, focusRequest, send, changeBaseDate };
}
