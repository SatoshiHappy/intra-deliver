import type { RecordAddonAction, RecordFieldDefinition } from '@/types';

/** 後ろのモジュールが足す操作のボタンの形(押せるか・Tooltip に出す理由) */
export type AddonActionState = { disabled: boolean; tooltip: string | null };

/**
 * 操作のボタンの形(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5、
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1)。一覧は持ち主を見ないので held = false で呼ぶ。
 */
export function addonActionState(
    action: RecordAddonAction,
    held: boolean,
): AddonActionState {
    if (held && action.changesValues) {
        return { disabled: true, tooltip: null };
    }

    return action.disabledReason === null
        ? { disabled: false, tooltip: null }
        : { disabled: true, tooltip: action.disabledReason };
}

/**
 * 出す操作(部品の登録がある操作だけ。並びはそのまま)と、登録の無い部品の名前(作りの誤り。呼び手が報告する)。
 */
export function shownAddonActions(
    actions: RecordAddonAction[],
    hasDialog: (name: string) => boolean,
): { shown: RecordAddonAction[]; missing: string[] } {
    const shown = actions.filter((action) => hasDialog(action.dialog));
    const missing = actions
        .filter((action) => !hasDialog(action.dialog))
        .map((action) => action.dialog);

    return { shown, missing };
}

/**
 * 一覧の行の札を出す列の項目のコード(null =「#」の列。置き場 = 業務の画面.md の 2.5)。
 */
export function addonBadgeColumn(
    listed: Pick<RecordFieldDefinition, 'code' | 'isTitle'>[],
): string | null {
    return listed.find((field) => field.isTitle)?.code ?? null;
}

/**
 * 対応表(JSON のオブジェクト)から自分のキーの値だけを引く。項目のコードが length・constructor のような組み込みの名前でも、
 * 継いだ値を引かないため。無ければ undefined。
 */
export function ownValue<T>(
    map: Record<string, T>,
    key: string | number,
): T | undefined {
    return Object.hasOwn(map, key) ? map[key] : undefined;
}
