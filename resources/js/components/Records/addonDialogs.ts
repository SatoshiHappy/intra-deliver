import type { ComponentType } from 'react';

/**
 * 後ろのモジュールのダイアログの部品が受ける props(values = 操作の props。部品の責務 = docs の
 * 設計/業務データの入れ物/画面/業務の画面.md の 2.5)。
 */
export type RecordAddonDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    values: Record<string, string | number | boolean | null>;
};

/** 部品の名前(<モジュール名の小文字>.<名前>)→ 部品 */
const dialogs = new Map<string, ComponentType<RecordAddonDialogProps>>();

/**
 * 後ろのモジュールの部品を名前で登録する。Records の画面は後ろのモジュールを読み込めないので、どこにでも依存できる組み立ての入口
 * (resources/js/app.tsx)が呼ぶ(同じ 2.5)。同じ名前を 2 度登録するのは作りの誤り。
 */
export function registerRecordAddonDialog(
    name: string,
    component: ComponentType<RecordAddonDialogProps>,
): void {
    if (dialogs.has(name)) {
        throw new Error(`後ろのモジュールの部品 ${name} を 2 度登録した`);
    }

    dialogs.set(name, component);
}

/**
 * 名前の部品(登録が無ければ undefined)。
 */
export function recordAddonDialog(
    name: string,
): ComponentType<RecordAddonDialogProps> | undefined {
    return dialogs.get(name);
}
