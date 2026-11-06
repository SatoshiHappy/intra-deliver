<?php

namespace App\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;

/**
 * ステップの設定の保存の入力(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 5.1 節、処理期限と確認する人 =
 * 設計/ワークフロー/案件の運用/定義の管理.md の 2 節)。欄の名前は入力のキー(snake_case)の camelCase。
 * 送られなかった欄は missing に入力のキーで並べる(欄の値は null。送られた null と分けるため = 5.1 節の「必須」「任意」の欄は空でも送る)。
 */
final readonly class NodeSettingsInput
{
    /**
     * @param  list<SelectionCondition>|null  $targets  対象者の行(指定の種類が fixed のとき)
     * @param  string|null  $designatorNodeCode  指定するノードのコード(同じ版)
     * @param  list<string>|null  $sendBackNodeCodes  差戻しできる先のノードのコード(同じ版)
     * @param  int|null  $deadlineDays  処理期限の日数(null = 期限なし)
     * @param  list<int>|null  $confirmers  確認する人のユーザーの id(並びと重複は見ない)
     * @param  list<string>  $missing  送られなかった欄の入力のキー
     */
    public function __construct(
        public ?WorkflowTargetKind $targetKind = null,
        public ?array $targets = null,
        public ?WorkflowRelativeBase $relativeBase = null,
        public ?WorkflowRelativeScope $relativeScope = null,
        public ?int $companyPostId = null,
        public ?string $designatorNodeCode = null,
        public ?int $minAssignees = null,
        public ?int $maxAssignees = null,
        public ?bool $preventsApplicantApproval = null,
        public ?array $sendBackNodeCodes = null,
        public ?WorkflowDecisionRule $decisionRule = null,
        public ?int $decisionValue = null,
        public ?bool $preventsAttachmentAdd = null,
        public ?int $deadlineDays = null,
        public ?array $confirmers = null,
        public array $missing = [],
    ) {}
}
