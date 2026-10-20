import type {
    ModuleCategoryChoice,
    RecordAdminModuleForm,
    RowAccessScope,
} from '@/types';

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
    row_access_scope: RowAccessScope;
};

/**
 * フォームの初期値(今の定義。明細のモジュールの持たない値は空・偽・全員にしておき、送らない)。
 */
export function initialModuleData(
    module: RecordAdminModuleForm,
): ModuleFormData {
    return {
        name: module.name,
        description: module.description ?? '',
        icon: module.icon ?? '',
        is_visible: module.isVisible === true,
        categories: module.categoryIds ?? [],
        row_access_scope: module.rowAccessScope ?? 'all',
    };
}

/**
 * 業務処理の入力。明細のモジュールはアイコン・表示の状態・分類・見える範囲を持たないので名前と説明だけ(業務処理は null 以外を拒む)。
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
