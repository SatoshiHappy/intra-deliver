import { formatDatetime } from '@/lib/recordValues';
import type { WorkflowAction, WorkflowPerson } from '@/types';

/**
 * ワークフローの画面の語(docs の 設計/ワークフロー/画面/README.md 1.2・画面/ホームと意匠とE2E.md 8 節)。人の表示・処理の種別の名前・改訂番号の不一致の文言。
 */

/** 処理の種別の名前(履歴・フロー情報の札に添える) */
export const ACTION_LABELS: Record<WorkflowAction, string> = {
    apply: '申請',
    reapply: '再申請',
    approve: '承認',
    approve_early: '承認終了',
    reject: '否認',
    send_back: '差戻し',
    pull_back: '引戻し',
    withdraw: '取止め',
    hold: '保留',
    release: '保留解除',
    transfer: '担当変更',
    retarget: '処理担当者の変更',
    reexpand: '再展開',
    move_back: 'ステップの移動(戻る)',
    move_forward: 'ステップの移動(進む)',
    move_finish: 'ステップの移動(終了)',
    reassign: '指定の変更',
};

/**
 * 改訂番号の不一致の文言(サーバーの App\Actions\Workflow\CaseOperationErrors::STALE_REVISION と同じ文字列。tests/Architecture/WorkflowStaleRevisionMirrorTest が結ぶ。キー case の文言がこれなら、
 * ダイアログは文言の後に「再表示」のリンクを添える = 画面/申請と案件.md 6 節)。
 */
export const STALE_REVISION_MESSAGE =
    '画面を表示した後に申請が変更されました。再表示してください';

/**
 * 人の表示(名前、無ければコード = 業務データの入れ物の参照の表示と同じ)。
 */
export function personName(person: WorkflowPerson): string {
    return person.name ?? person.code;
}

/**
 * 処理対象者の並びの文(3 人までは「<名前>さん、<名前>さん」、3 人を超えたら先頭の人で「<名前>さんほか N 人」= 画面/申請と案件.md 3 節)。0 人なら空文字。
 */
export function targetsText(targets: WorkflowPerson[]): string {
    if (targets.length > 3) {
        return `${personName(targets[0])}さんほか ${targets.length - 1} 人`;
    }

    return targets.map((person) => `${personName(person)}さん`).join('、');
}

/**
 * 日時の文字列の Asia/Tokyo の日付(YYYY/MM/DD。申請日・完了日。業務データの入れ物の日時の表示から導く)。
 */
export function formatDateOf(datetime: string): string {
    return formatDatetime(datetime).slice(0, 10);
}
