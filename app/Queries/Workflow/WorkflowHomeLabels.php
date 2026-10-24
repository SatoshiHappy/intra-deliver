<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;

/**
 * ホームのワークフローの節の語と札(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節の内訳・ステップの表(ルートの深さ/認可と一覧.md 2 節で読み替え)、8 節の状態の札と
 * 履歴の処理の種別の札)。値だけを持つ。
 */
final class WorkflowHomeLabels
{
    /**
     * 案件の状態の札の状態 → [語, 札の色](8 節の案件・ノードの状態の札。印なし。保留中 = 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節)。
     * 並びは内訳の並び
     */
    public const CURRENT_NODE_STATES = [
        WorkflowNodeState::Waiting->value => ['承認待ち', 'pending'],
        WorkflowNodeState::Reapply->value => ['再申請待ち', 'returned'],
        WorkflowNodeState::Hold->value => ['保留中', 'returned'],
    ];

    /** 今月の処理済みの種別 → [語, 札の色, 印](8 節の履歴の処理の種別の札)。並びは内訳の並び */
    public const RESULTS = [
        WorkflowAction::Approve->value => ['承認', 'settled', '✓'],
        WorkflowAction::ApproveEarly->value => ['承認終了', 'settled', '✓'],
        WorkflowAction::Reject->value => ['否認', 'rejected', '×'],
        WorkflowAction::SendBack->value => ['差戻し', 'returned', null],
    ];

    /**
     * 経路のステップの state と stateLabel(ノードの状態どおり = ルートの深さ/認可と一覧.md 2 節の H2・H3): 処理済み・通過 → done、承認待ち・展開中 → current、
     * 再申請待ち → returned、到達していない → upcoming、省略 → skipped。stateLabel は点の状態と 1 対 1 の語(通過 = 処理済み、展開中 = 承認待ち。ユーザーの決定 S1)。
     *
     * @return array{'done'|'current'|'returned'|'upcoming'|'skipped', string}
     */
    public static function step(WorkflowNodeState $state): array
    {
        return match ($state) {
            WorkflowNodeState::Done => ['done', '処理済み'],
            WorkflowNodeState::Passed => ['done', '処理済み'],
            WorkflowNodeState::Waiting => ['current', '承認待ち'],
            WorkflowNodeState::Running => ['current', '承認待ち'],
            WorkflowNodeState::Reapply => ['returned', '再申請待ち'],
            WorkflowNodeState::Unreached => ['upcoming', '到達していない'],
            WorkflowNodeState::Skipped => ['skipped', '省略'],
            // 保留中は現在のノードの点で、語だけ変える(設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節)
            WorkflowNodeState::Hold => ['current', '保留中'],
        };
    }

    /**
     * 案件の詳細(図・表・合議の進み具合)のステップの state と stateLabel。{@see self::step()} の結果のうち、保留中のノードだけ state を `hold` にする
     * (差戻しの色と一時停止の印の札 = 設計/ワークフロー/案件の運用/画面/README.md の 8.1 節。
     * ホームの経路は step() のまま current = 設計/ワークフロー/案件の運用/認可と一覧.md の 2 節)。
     *
     * @return array{'done'|'current'|'returned'|'upcoming'|'skipped'|'hold', string}
     */
    public static function detailStep(WorkflowNodeState $state): array
    {
        [$stepState, $label] = self::step($state);

        return [$state === WorkflowNodeState::Hold ? 'hold' : $stepState, $label];
    }

    /**
     * 件数カードの内訳の 1 行: 語ごとの件数を「<語> <件数>」にして、0 の語を出さずに「・」で繋ぐ(全部 0 なら null)。
     *
     * @param  array<string, array{0: string, 1: string, 2?: string|null}>  $labels  値 → [語, …](並びは内訳の並び)
     * @param  array<string, int>  $counts  値 → 件数(無い値は 0)
     */
    public static function note(array $labels, array $counts): ?string
    {
        $words = [];

        foreach ($labels as $value => $label) {
            $count = $counts[$value] ?? 0;

            if ($count > 0) {
                $words[] = "{$label[0]} {$count}";
            }
        }

        return $words === [] ? null : implode('・', $words);
    }
}
