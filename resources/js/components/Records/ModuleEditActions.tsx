import { Link } from '@inertiajs/react';
import { FormActions } from '@/components/FormLayout';
import { AttachmentDisableDialog } from '@/components/Records/AttachmentDisableDialog';
import { Button } from '@/components/ui/button';
import type { useModuleEditSubmit } from '@/hooks/Records/use-module-edit-submit';
import type { RouteDefinition } from '@/wayfinder';

/**
 * 業務モジュールの編集のボタンと、添付の許可の解除の確かめ(docs の
 * 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 2 節)。
 */
export function ModuleEditActions({
    cancelHref,
    processing,
    submission,
}: {
    cancelHref: RouteDefinition<'get'>;
    processing: boolean;
    submission: ReturnType<typeof useModuleEditSubmit>;
}) {
    return (
        <>
            <FormActions>
                <Button variant="outline" asChild>
                    <Link href={cancelHref}>キャンセル</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    更新
                </Button>
            </FormActions>
            <AttachmentDisableDialog
                open={submission.open}
                notices={submission.notices}
                processing={processing}
                onCancel={submission.cancel}
                onConfirm={submission.confirm}
            />
        </>
    );
}
