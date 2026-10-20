import type { AuthzGridSetting } from '@/types';

/** セルの直接の設定(継承・設定なしは none。サーバーの from・to と同じ値) */
export type DirectSetting = 'permit' | 'deny' | 'none';

/** 押したセル(行 × 対象者 × アクション)と、そのとき画面に出ていた設定 */
export type CellTarget = {
    resourceGroupId: number;
    subjectGroupId: number;
    action: string;
    setting: AuthzGridSetting | undefined;
};

/** 同時の編集でサーバーが返す文(キー cell)。画面はこの文のときだけ「再読み込み」を添える */
const STALE_CELL_MESSAGE =
    'ほかの利用者が変更しました。画面を再読み込みしてください。';

/**
 * 画面が見ていた直接の設定。継承のセルと設定の無いセルは none(未設定)として扱う。
 */
export function directSettingOf(
    setting: AuthzGridSetting | undefined,
): DirectSetting {
    return setting === undefined || setting.inheritedFrom !== null
        ? 'none'
        : setting.effect;
}

/**
 * 押したときの次の設定(許可 → 禁止 → 未設定 → 許可。docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.1)。
 */
export function nextSetting(current: DirectSetting): DirectSetting {
    switch (current) {
        case 'permit':
            return 'deny';
        case 'deny':
            return 'none';
        case 'none':
            return 'permit';
    }
}

/**
 * セルの切替の入力(authz.editor.cells.update。docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.2)。
 */
export function cellPayload(resourceType: string, target: CellTarget) {
    const from = directSettingOf(target.setting);

    return {
        resource_group_id: target.resourceGroupId,
        subject_group_id: target.subjectGroupId,
        type: resourceType,
        action: target.action,
        from,
        to: nextSetting(from),
    };
}

/**
 * 保存中のセルを見分ける鍵(行 × 対象者 × アクション)。
 */
export function cellKey(
    resourceGroupId: number,
    subjectGroupId: number,
    action: string,
): string {
    return `${resourceGroupId}:${subjectGroupId}:${action}`;
}

/**
 * 同時の編集の文か(ほかのセルの誤り = 守りの文と分けて「再読み込み」を添えるため)。
 */
export function isStaleCellMessage(message: string): boolean {
    return message === STALE_CELL_MESSAGE;
}
