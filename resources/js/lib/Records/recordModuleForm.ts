import type { ModuleCategoryChoice, RecordAdminModuleForm } from '@/types';

/**
 * 管理の業務モジュールの編集のフォームの値と、業務処理の入力への変換(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.5、
 * 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md 2 節)。
 */

export type ModuleFormData = {
    name: string;
    description: string;
    icon: string;
    is_visible: boolean;
    categories: string[];
    is_attachment_enabled: boolean;
    row_access_restricted_by_default: boolean;
};

/**
 * フォームの初期値(今の定義。明細のモジュールの持たない値は空・偽・使用する・制限しないにしておき、送らない)。
 * 表示の止めは {@link withVisibilityStop} で当てる。
 */
export function initialModuleData(
    module: RecordAdminModuleForm,
    visibilityStopReason: string | null = null,
): ModuleFormData {
    const data: ModuleFormData = {
        name: module.name,
        description: module.description ?? '',
        icon: module.icon ?? '',
        is_visible: module.isVisible === true,
        categories: module.categoryIds ?? [],
        is_attachment_enabled: module.isAttachmentEnabled !== false,
        row_access_restricted_by_default:
            module.rowAccessRestrictedByDefault === true,
    };

    return withVisibilityStop(data, visibilityStopReason);
}

/**
 * 表示の止めを当てたフォームの値(管理の画面.md 3.5)。初期値と、開いている間に止めが立ったときの両方をこれで決める。
 */
export function withVisibilityStop(
    data: ModuleFormData,
    visibilityStopReason: string | null,
): ModuleFormData {
    return visibilityStopReason === null
        ? data
        : { ...data, is_visible: false };
}

/**
 * 業務処理の入力。明細のモジュールはアイコン・表示の状態・分類・添付の使用・参照制限の既定を持たないので名前と説明だけ(業務処理は null 以外を拒む)。
 * サイドバーに出さないなら分類は [](選んだ状態は画面の中だけで保つ)。分類は一覧の並びの順で送る。
 */
export function toModulePayload(
    data: ModuleFormData,
    isDetails: boolean,
    categories: ModuleCategoryChoice[],
): Record<string, unknown> {
    if (isDetails) {
        return { name: data.name, description: data.description };
    }

    return {
        ...data,
        categories: data.is_visible
            ? categories
                  .filter((category) => data.categories.includes(category.id))
                  .map((category) => category.id)
            : [],
    };
}
