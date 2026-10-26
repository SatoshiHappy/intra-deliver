import type { RecordApplyEntry } from '@/types';

/**
 * 詳細の「申請する」の出し方の結果。入口の判定はサーバーが済ませて返すので、画面は入口の数と持ち主だけで決める
 * (規則の正本 = docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 2 節)。
 */
export type RecordApplyButtonKind =
    | { kind: 'none' }
    | { kind: 'disabled' }
    | { kind: 'link'; href: string }
    | { kind: 'dialog'; entries: RecordApplyEntry[] };

export function recordApplyButtonOf(
    entries: RecordApplyEntry[],
    held: boolean,
): RecordApplyButtonKind {
    const [only, ...rest] = entries;

    if (only === undefined) {
        return { kind: 'none' };
    }

    if (held) {
        return { kind: 'disabled' };
    }

    return rest.length === 0
        ? { kind: 'link', href: only.url }
        : { kind: 'dialog', entries };
}

/**
 * フローを選ぶダイアログの題と本文(文の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 8 節)。
 */
export function recordApplyDialogTextOf(heading: string, count: number) {
    return {
        title: '申請するフローを選択',
        description: `${heading}を申請できるフローが${count}つあります。`,
    };
}
