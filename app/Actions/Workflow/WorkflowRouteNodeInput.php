<?php

namespace App\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Workflow\WorkflowNodeKind;

/**
 * ルートのノード 1 つの入力({@see CreateWorkflowFlow} に渡す。位置は並びの順)。処理対象者の指定は対象者で指定で、条件と表示名を渡して
 * フローを作る業務処理が専用の対象者(用途 selection)を作る(docs の 設計/ワークフロー/データモデル/表.md 2.3)。
 */
final readonly class WorkflowRouteNodeInput
{
    /**
     * @param  list<SelectionCondition>  $targetConditions  処理対象者の条件(1 件以上)
     * @param  array<string, string>  $targetTranslations  処理対象者の表示名(ロケールのコード → 名前)
     * @param  int|null  $deadlineDays  処理期限の日数(NULL = 期限なし)
     * @param  int|null  $confirmSubjectGroupId  確認する人の対象者(用途 selection。NULL = 確認なし)
     */
    public function __construct(
        public string $code,
        public string $name,
        public WorkflowNodeKind $kind,
        public array $targetConditions,
        public array $targetTranslations,
        public ?int $deadlineDays = null,
        public ?int $confirmSubjectGroupId = null,
    ) {}
}
