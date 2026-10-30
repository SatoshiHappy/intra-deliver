import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import {
    initialModuleData,
    withVisibilityStop,
} from '@/lib/Records/recordModuleForm';
import type { RecordAdminModuleForm } from '@/types';

/**
 * 管理の業務モジュールの編集のフォーム(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.5)。開いている間に止めが立ったとき
 * (422 の後の props で visibilityStopReason が null でなくなったとき)も、{@link withVisibilityStop} でそろえる。
 */
export function useRecordModuleForm(
    module: RecordAdminModuleForm,
    visibilityStopReason: string | null,
) {
    const form = useForm(initialModuleData(module, visibilityStopReason));
    const { setData } = form;

    useEffect(() => {
        setData((data) => withVisibilityStop(data, visibilityStopReason));
    }, [visibilityStopReason, setData]);

    return form;
}
