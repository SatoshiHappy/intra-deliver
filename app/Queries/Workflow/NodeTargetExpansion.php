<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Queries\Master\Api\CurrentDepartment;
use App\Queries\Master\Api\DepartmentMembers;
use App\Queries\Master\Api\DepartmentTree;
use App\Queries\Master\Api\EffectiveUsers;
use DateTimeInterface;
use LogicException;

/**
 * 処理対象者の展開の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 4 節)。人の有効性を見る日は、呼び手が渡す申請基準日 d。
 * 対象者で指定 = 認可の展開の口(4.2)、起点で指定 = 起点の組織 → 範囲の組織 → 所属(と役職)を共通マスタの口で引く(4.3。組織の階層と所属を自分で問い合わせない)。
 * 処理の時に指定(5 節)の人は指定の行から決まるので、ここでは有効性だけを見る({@see self::allEffectiveOn()})。0 人かどうかの判定(4.6)は呼び手。鍵を取らない。
 */
class NodeTargetExpansion
{
    public function __construct(
        private SubjectGroupMembership $membership,
        private CurrentDepartment $currentDepartment,
        private DepartmentTree $tree,
        private DepartmentMembers $members,
        private EffectiveUsers $effectiveUsers,
    ) {}

    /**
     * 前のノードからの到達で、対象者で指定・起点で指定のノードを展開する(案件のノードと、申請の画面の先読みの版のノード = 同じ列を持つ。ルートの深さ/画面/案件の側の画面.md 5 節)。
     *
     * @param  int|null  $applicantDepartmentId  案件の担当組織(申請者が起点のときの起点の組織)
     * @param  int|null  $previousDepartmentId  前処理者の起点の組織({@see self::originDepartmentOn()}。前処理者が起点のときだけ使う)
     * @return list<int> 処理対象者のユーザーの id(昇順。0 人なら空)
     *
     * @throws LogicException 処理の時に指定のノード・指定の種類を持たないノードのとき(呼び手の誤り)
     */
    public function expand(WorkflowRouteNode|WorkflowCaseNode $node, ?int $applicantDepartmentId, ?int $previousDepartmentId, DateTimeInterface $date): array
    {
        return match ($node->target_kind) {
            WorkflowTargetKind::Fixed => $this->membership->memberUserIdsOn($node->authz_subject_group_id ?? throw new LogicException("ノード {$node->id} の対象者が無い"), $date),
            WorkflowTargetKind::Relative => $this->relative(
                $node->relative_scope ?? throw new LogicException("ノード {$node->id} の範囲が無い"),
                $node->company_post_id,
                $node->relative_base === WorkflowRelativeBase::Previous ? $previousDepartmentId : $applicantDepartmentId,
                $date,
            ),
            default => throw new LogicException("ノード {$node->id} は対象者で指定・起点で指定でないので展開できない"),
        };
    }

    /**
     * 起点で指定の展開(4.3 の手順 2・3): 範囲の組織に d に所属する人(役職ありなら、その役職の所属役職を持つ人)。起点の組織が無い・範囲の組織が無い(根の上位組織)なら 0 人。
     *
     * @return list<int>
     */
    public function relative(WorkflowRelativeScope $scope, ?int $companyPostId, ?int $originDepartmentId, DateTimeInterface $date): array
    {
        if ($originDepartmentId === null) {
            return [];
        }

        $departmentIds = match ($scope) {
            WorkflowRelativeScope::Own => [$originDepartmentId],
            WorkflowRelativeScope::Parent => array_slice($this->tree->ancestorIdsOn($originDepartmentId, $date), 0, 1),
            WorkflowRelativeScope::Ancestors => $this->tree->ancestorIdsOn($originDepartmentId, $date),
        };

        return $departmentIds === [] ? [] : $this->members->userIdsOn($departmentIds, $companyPostId, $date);
    }

    /**
     * 前処理者(操作の処理者)の起点の組織(4.3 の手順 1。担当組織と同じ口の規則 = 選んだ組織が d の所属にあればそれ、無ければ主所属、所属が無ければ null)。
     *
     * @param  int|null  $selectedDepartmentId  その操作の選んだ組織
     */
    public function originDepartmentOn(int $userId, ?int $selectedDepartmentId, DateTimeInterface $date): ?int
    {
        return $this->currentDepartment->departmentIdOn($userId, $selectedDepartmentId, $date);
    }

    /**
     * ノードに記録した前処理者と起点の組織から求める起点の組織(設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節の 1・2)。
     */
    public function recordedOriginOf(WorkflowCase $case, WorkflowCaseNode $node, DateTimeInterface $date): ?int
    {
        $previousUserId = $node->previous_user_id;

        return match (true) {
            $previousUserId === null => $case->department_id,
            $node->previous_department_id !== null => $node->previous_department_id,
            $previousUserId === $case->user_id => $case->department_id,
            default => $this->originDepartmentOn($previousUserId, null, $date),
        };
    }

    /**
     * 渡した人がすべて d に有効か(指定した人の到達・後のノードからの到達で戻す相手 = 4.5・5.3。共通マスタの口で一括に引く)。空なら真。
     *
     * @param  list<int>  $userIds
     */
    public function allEffectiveOn(array $userIds, DateTimeInterface $date): bool
    {
        $unique = array_values(array_unique($userIds));

        return count($this->effectiveUsers->idsOn($unique, $date)) === count($unique);
    }
}
