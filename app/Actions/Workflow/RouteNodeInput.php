<?php

namespace App\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;

/**
 * 形つきの版のノード 1 つの入力({@see RouteVersionWriter}。位置は並びの順。docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.3 の列)。
 * 同じ版のノードはコードで指す(指定するノード・対の終了・差戻しできる先)。対象者で指定は、条件と表示名(専用の対象者を作る)か、既にある対象者の id
 * (版の複製で同じ対象者を指す = 定義の表.md 2.3)のどちらか。列の組(種類と指定の種類など)は DB の検査制約が止める。
 */
final readonly class RouteNodeInput
{
    /**
     * @param  list<SelectionCondition>  $targetConditions  対象者で指定の条件(新しく専用の対象者を作るとき)
     * @param  array<string, string>  $targetTranslations  その表示名(ロケールのコード → 名前)
     * @param  int|null  $subjectGroupId  対象者で指定の既にある対象者(条件の代わり)
     * @param  string|null  $designatorCode  処理の時に指定の指定するノードのコード
     * @param  string|null  $closingCode  分岐開始・同期開始の対の終了のコード
     * @param  list<string>  $sendBackCodes  差戻しできる先のコード
     * @param  int|null  $deadlineDays  処理期限の日数(NULL = 期限なし)
     * @param  int|null  $confirmSubjectGroupId  確認する人の対象者(用途 selection。NULL = 確認なし)
     */
    public function __construct(
        public string $code,
        public string $name,
        public WorkflowNodeKind $kind,
        public ?WorkflowTargetKind $targetKind = null,
        public array $targetConditions = [],
        public array $targetTranslations = [],
        public ?int $subjectGroupId = null,
        public ?WorkflowRelativeBase $relativeBase = null,
        public ?WorkflowRelativeScope $relativeScope = null,
        public ?int $companyPostId = null,
        public ?string $designatorCode = null,
        public ?int $minAssignees = null,
        public ?int $maxAssignees = null,
        public ?WorkflowDecisionRule $decisionRule = null,
        public ?int $decisionValue = null,
        public bool $preventsApplicantApproval = false,
        public ?string $closingCode = null,
        public array $sendBackCodes = [],
        public ?int $deadlineDays = null,
        public ?int $confirmSubjectGroupId = null,
    ) {}

    /**
     * 同じ版の中で指すノードのコード(書く順を決める = {@see ReferenceFirstOrder})。
     *
     * @return list<string>
     */
    public function referencedCodes(): array
    {
        return array_values(array_filter([$this->closingCode, $this->designatorCode], fn (?string $code) => $code !== null));
    }
}
