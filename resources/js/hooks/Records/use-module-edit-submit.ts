import { useState } from 'react';
import type { FormEvent } from 'react';
import type { useRecordModuleForm } from '@/hooks/Records/use-record-module-form';
import {
    needsAttachmentDisableConfirm,
    toModulePayload,
} from '@/lib/Records/recordModuleForm';
import { update } from '@/routes/records/admin/modules';
import type { ModuleCategoryChoice, RecordAdminModuleForm } from '@/types';

/**
 * 業務モジュールの編集の送信(docs の 設計/業務データの入れ物/画面/管理の画面.md の 3.5)と、その前の添付の許可の解除の確かめ
 * (docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 2 節)。Enter での送信も同じ確かめを通すため、
 * フォームの送信をここで受ける。
 */
export function useModuleEditSubmit(
    form: ReturnType<typeof useRecordModuleForm>,
    module: RecordAdminModuleForm,
    categories: ModuleCategoryChoice[],
    notices: string[],
) {
    const [open, setOpen] = useState(false);
    const send = () => {
        form.transform((data) =>
            toModulePayload(data, module.isDetails, categories),
        );
        form.submit(update(module.code));
    };

    return {
        open,
        notices,
        submit: (event: FormEvent) => {
            event.preventDefault();

            if (needsAttachmentDisableConfirm(module, form.data, notices)) {
                setOpen(true);
            } else {
                send();
            }
        },
        confirm: () => {
            // 閉じる途中に「更新」をもう一度押しても 2 回送らない
            if (!open || form.processing) {
                return;
            }

            setOpen(false);
            send();
        },
        cancel: () => setOpen(false),
    };
}
