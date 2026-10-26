import { formatDate } from '@/lib/recordValues';
import type {
    StatusTone,
    WorkflowProxyKind,
    WorkflowProxyStatus,
} from '@/types';

/**
 * 代理の設定・代理の管理の画面の語と文(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節)。
 * 日付は画面の日付の表示(YYYY/MM/DD)で出し、終了日は最後の有効な日のまま出す。
 */

/** 種類の語 */
export const PROXY_KIND_LABELS: Record<WorkflowProxyKind, string> = {
    approve: '承認',
    apply: '申請',
};

/** 状態の札の語と色(有効 = 承認待ちの色、開始前・終了 = 処理の結果の色 = 画面/README.md の 8.1 節) */
export const PROXY_STATUS_BADGES: Record<
    WorkflowProxyStatus,
    { label: string; tone: StatusTone }
> = {
    active: { label: '有効', tone: 'pending' },
    upcoming: { label: '開始前', tone: 'settled' },
    ended: { label: '終了', tone: 'settled' },
};

/** 対象の語(フローを指定しない代理) */
export const ALL_FLOWS_LABEL = 'すべてのフロー';

/**
 * 対象の列の文(フロー名。null = すべてのフロー)。
 */
export function proxyTargetText(flowName: string | null): string {
    return flowName ?? ALL_FLOWS_LABEL;
}

/**
 * 期間の文(「{開始日} から {終了日}」。終了日なしは「{開始日} から 終了日なし」)。
 */
export function proxyPeriodText(
    startDate: string,
    endDate: string | null,
): string {
    return `${formatDate(startDate)} から ${
        endDate === null ? '終了日なし' : formatDate(endDate)
    }`;
}

/**
 * 代理を追加するダイアログの注意の囲み「追加後の動き」の文(承認 = 代理先の未処理に代理元の承認待ちが出る、申請 = 代理先が代理元として申請できる)。
 */
export function proxyAfterNoticeText(
    kind: WorkflowProxyKind,
    proxyName: string,
    principalName: string,
): string {
    const notified = `${proxyName}さんへ通知が届きます。`;

    return kind === 'apply'
        ? `${proxyName}さんが、${principalName}さんとして申請できるようになります。${notified}`
        : `${proxyName}さんの未処理に、${principalName}さんの承認待ちの申請が表示されます。${notified}`;
}
