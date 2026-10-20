/**
 * 状態色の名前(docs の 設計/画面の意匠/トークンとダーク.md 1.2。札の地と字)。
 * 共通の札 components/StatusBadge.tsx と、ホームの表の節(types/menu.ts の HomeTableSection)が使う。
 */
export type StatusTone = 'pending' | 'returned' | 'settled' | 'rejected';

/**
 * ステップの状態の値(サーバーの写しの表の 1 か所 App\Queries\Workflow\WorkflowHomeLabels::step() の state。ルートの深さ/認可と一覧.md 2 節)。
 * ホームの経路の節(types/menu.ts の HomeRouteStep)と案件の詳細のフロー情報(types/workflow.ts の WorkflowStepState)が使う。
 */
export type RouteStepState =
    | 'done'
    | 'current'
    | 'returned'
    | 'upcoming'
    | 'skipped';
