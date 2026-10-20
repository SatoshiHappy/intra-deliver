import type { AuthzGridJudgement } from '@/types';

/**
 * 認可設定の画面の語(ja だけ。docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.2)。
 * 表に無いリソースタイプ・アクションの ID はそのまま出す。
 */

const RESOURCE_TYPE_LABELS: Record<string, string> = {
    service: '画面・処理',
    'im-menu-group': 'メニューグループ',
    'record-module': '業務モジュール',
};

const ACTION_LABELS: Record<string, string> = {
    execute: '実行',
    read: '参照',
    admin: '管理',
    create: '登録',
    update: '更新',
    delete: '削除',
    'read-all': '全件の参照',
};

/** 判定の結果・エフェクトの画面の語 */
export const RESULT_LABELS = {
    permit: '許可',
    deny: '禁止',
    block: '閉塞',
} as const;

/**
 * リソースタイプの画面の語(表に無ければ ID)。
 */
export function resourceTypeLabel(resourceType: string): string {
    return RESOURCE_TYPE_LABELS[resourceType] ?? resourceType;
}

/**
 * アクションの画面の語(表に無ければ ID)。
 */
export function actionLabel(action: string): string {
    return ACTION_LABELS[action] ?? action;
}

/**
 * 閉塞の行の札(全部のアクションが閉塞なら「閉塞中」、一部なら閉塞のアクションを括弧に並べる)。
 */
export function blockedLabel(blocked: string[], actions: string[]): string {
    return blocked.length === actions.length
        ? '閉塞中'
        : `閉塞中(${blocked.map(actionLabel).join('、')})`;
}

/**
 * 判定のセルの title(効いた対象者の無い禁止 = 既定の禁止だけ。ほかは出さない)。
 */
export function judgementTitle(
    result: AuthzGridJudgement['result'],
    names: string[],
): string | undefined {
    return result === 'deny' && names.length === 0
        ? '該当する設定なし(既定の禁止)'
        : undefined;
}
