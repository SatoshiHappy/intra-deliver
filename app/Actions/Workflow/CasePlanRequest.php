<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\CaseActor;
use App\Support\AccessContext;

/**
 * 計画の入力({@see CaseRoutePlanner::plan()}。docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 9 節の 1)。業務処理は {@see self::by()} で作り、
 * 前処理者と選んだ組織は {@see CaseActor} の 1 か所の口(previousUserId・previousSelectedDepartmentId・selectedDepartmentFor)から決まる
 * (処理対象者.md 4.3 の手順 1・01 の依頼 F13。3b の代理も口の中で決める)。公開のコンストラクタは計画の入力の型(テストが値を直に渡す)。
 */
final readonly class CasePlanRequest
{
    /**
     * @param  WorkflowCase  $case  鍵を取った案件の行
     * @param  int  $nodeId  操作するノード(申請・再申請・取止めは申請のノード)
     * @param  WorkflowAction  $action  操作(書く履歴の行の種別)
     * @param  int  $previousUserId  前処理者(処理対象者.md 4.3。起点で指定の前処理者の起点)
     * @param  int|null  $selectedDepartmentId  その操作の選んだ組織(前処理者の起点の組織を決める)
     * @param  array<int, list<int>>  $assignments  この操作の検証済みの指定(指定されたノードの id → 人の並び。承認で指定があるときだけ)
     * @param  int|null  $sendBackNodeId  差戻し先(差戻しだけ。呼び手が候補 = 操作.md 10.4 にあることを確かめた)
     * @param  WorkflowCaseHistory|null  $revertedHistory  引戻す行(引戻しだけ。操作するノードの最新の履歴の行で、呼び手が 10.5 の 1 規則に合うことを確かめた)
     */
    public function __construct(
        public WorkflowCase $case,
        public int $nodeId,
        public WorkflowAction $action,
        public int $previousUserId,
        public ?int $selectedDepartmentId,
        public array $assignments = [],
        public ?int $sendBackNodeId = null,
        public ?WorkflowCaseHistory $revertedHistory = null,
    ) {}

    /**
     * 操作する人と判定を求める者から作る(前処理者と選んだ組織は {@see CaseActor} の 1 か所の口 = 01 の依頼 F13。8 つの業務処理が使う)。
     *
     * @param  array<int, list<int>>  $assignments
     */
    public static function by(CaseActor $actor, AccessContext $context, WorkflowCase $case, int $nodeId, WorkflowAction $action, array $assignments = [], ?int $sendBackNodeId = null, ?WorkflowCaseHistory $revertedHistory = null): self
    {
        $selected = $actor->previousSelectedDepartmentId($context, $case->user_id, $case->department_id);

        return new self($case, $nodeId, $action, $actor->previousUserId(), $selected, $assignments, $sendBackNodeId, $revertedHistory);
    }

    /**
     * 案件操作の計画の入力(設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節の 1・3)。管理者は前処理者にならないので、
     * 呼び手が求めた前処理者で作る。
     *
     * @param  int  $previousUserId  前処理者
     */
    public static function byManager(
        WorkflowCase $case,
        int $nodeId,
        WorkflowAction $action,
        int $previousUserId,
        ?int $sendBackNodeId = null,
    ): self {
        $selected = CaseActor::selectedDepartmentFor($previousUserId, $case->user_id, $case->department_id);

        return new self($case, $nodeId, $action, $previousUserId, $selected, sendBackNodeId: $sendBackNodeId);
    }
}
