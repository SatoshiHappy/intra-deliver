<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;

/**
 * 現在のノード 1 つ({@see CurrentNodes} の結果の要素。docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 1.3)。
 */
final readonly class CurrentNode
{
    /**
     * @param  int  $id  案件のノードの id
     * @param  string  $name  表示名(展開の行は「{親の名前} {子の名前}」= WorkflowCaseNode::displayName())
     * @param  string|null  $parentName  展開の行の親の名前(展開の行でなければ null)
     * @param  int|null  $sequence  展開の行の番号(展開の行でなければ null)
     * @param  list<int>  $targetUserIds  処理対象者のユーザーの id(昇順)
     */
    public function __construct(
        public int $id,
        public string $name,
        public WorkflowNodeKind $kind,
        public WorkflowNodeState $state,
        public ?string $parentName,
        public ?int $sequence,
        public array $targetUserIds,
    ) {}
}
