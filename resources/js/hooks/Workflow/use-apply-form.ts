import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useApplyReload } from '@/hooks/Workflow/use-apply-reload';
import { applyPayloadOf, initialApplyFormOf } from '@/lib/Workflow/applyForm';
import type { ApplyForm, ApplySendKind } from '@/lib/Workflow/applyForm';
import type { RecordFormData } from '@/lib/recordForm';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/**
 * 申請・一時保存・再申請のフォームの状態と送信(docs の 設計/ワークフロー/画面/申請と案件.md 3 節)。
 * focusRequest は送信の失敗ごとに増え、呼ぶ側が誤りのある欄へフォーカスを移す合図に使う。
 */
export function useApplyForm(props: WorkflowApplyProps) {
    const [initialData] = useState<ApplyForm>(() => initialApplyFormOf(props));
    const form = useForm<ApplyForm>(initialData);
    const errors = form.errors as Record<string, string | undefined>;
    const [focusRequest, setFocusRequest] = useState(0);
    const reload = useApplyReload(form, props);

    /** 送る内容は applyPayloadOf(申請者・連絡・申請基準日をどの操作で送るかもそこで決まる) */
    const send = (
        action: RouteDefinition<'post' | 'put'>,
        kind: ApplySendKind,
    ) => {
        form.transform((data) => applyPayloadOf(props, data, kind));
        form.submit(action, {
            onError: () => setFocusRequest((count) => count + 1),
        });
    };

    /** 業務データの欄の更新の口(申請対象の欄が変わったときの処理は、ここで更新の前後を見て足す) */
    const changeRecord = (update: (record: RecordFormData) => RecordFormData) =>
        form.setData((data) => ({ ...data, record: update(data.record) }));

    return { form, errors, focusRequest, send, changeRecord, ...reload };
}
