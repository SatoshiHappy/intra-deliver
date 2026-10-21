<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Queries\Workflow\CaseActor;

/**
 * 案件の操作の業務処理(一時保存の 3 つを除く)が返す結果(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 11 節 = 3b の差し込み口)。
 * 到達したノード = 操作の後に現在のノードで、操作の前に現在のノードでなかったもの(写しから戻したものを含む。{@see CaseRoutePlan::reachedNodes()} が
 * 計画の前後の現在のノードの集合の差で求める)。入口は移り先とトーストの文に、画面内通知の宛先({@see SendCaseNotices})は
 * 処理対象者が増えたノード・確認が開いたノード・完了の理由から作る。
 */
final readonly class CaseOperationResult
{
    /**
     * @param  int  $caseId  案件の id
     * @param  WorkflowAction  $action  操作(書いた履歴の行の種別)
     * @param  int  $historyId  書いた履歴の行の id
     * @param  array<int, list<int>>  $reachedNodes  到達したノードの id → 操作の後の処理対象者のユーザーの id(昇順)。ノードの id の昇順
     * @param  WorkflowCompletionReason|null  $completionReason  完了の理由(完了したときだけ)
     * @param  CaseActor  $actor  操作した人
     * @param  array<int, list<int>>  $targetAdditions  処理対象者が増えたノードの id → 操作の前にそのノードの処理対象者でなかった人(昇順。
     *                                                  設計/ワークフロー/案件の運用/案件の操作/操作.md の 11 節)。ノードの id の昇順
     * @param  array<int, list<int>>  $openedConfirmations  新しい処理の履歴の行で処理済みになり確認が開いたノードの id → 確認対象者(昇順。同じ 11 節)
     */
    public function __construct(
        public int $caseId,
        public WorkflowAction $action,
        public int $historyId,
        public array $reachedNodes,
        public ?WorkflowCompletionReason $completionReason,
        public CaseActor $actor,
        public array $targetAdditions = [],
        public array $openedConfirmations = [],
    ) {}
}
