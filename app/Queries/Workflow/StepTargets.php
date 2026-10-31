<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Master\Api\MasterNames;
use DateTimeInterface;
use LogicException;

/**
 * 版のノード・案件のノードの処理対象者の指定を、名前つきの {@see StepTarget} にする(設定から作る文の入力 = docs の
 * 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 9.3 節)。対象者の名前は {@see SubjectRows}、役職の名前は共通マスタの公開の口で引く。
 */
class StepTargets
{
    public function __construct(
        private SubjectRows $subjectRows,
        private MasterNames $names,
    ) {}

    /**
     * @param  list<WorkflowRouteNode|WorkflowCaseNode>  $nodes
     * @param  array<int, string>  $designatorNames  処理の時に指定するノードの id → 指定するノードの名前
     * @return array<int, StepTarget|null> ノードの id → 指定(指定を持たないノードは null)
     *
     * @throws LogicException 処理の時に指定するノードの指定するノードの名前・人数が無いとき
     */
    public function of(array $nodes, array $designatorNames, DateTimeInterface $date, string $localeCode): array
    {
        $groupIds = self::idsOf(array_map(fn ($node) => $node->authz_subject_group_id, $nodes));
        $postIds = self::idsOf(array_map(fn ($node) => $node->company_post_id, $nodes));
        $rows = $this->subjectRows->of($groupIds, $date, $localeCode);
        $posts = $postIds === [] ? [] : $this->names->companyPosts($postIds, $date, $localeCode);
        $targets = [];

        foreach ($nodes as $node) {
            $targets[$node->id] = match ($node->target_kind) {
                null => null,
                WorkflowTargetKind::Fixed => StepTarget::fixed($rows[(int) $node->authz_subject_group_id] ?? []),
                WorkflowTargetKind::Relative => $this->relative($node, $posts),
                WorkflowTargetKind::Designated => StepTarget::designated(
                    $designatorNames[$node->id] ?? throw new LogicException("ノード {$node->id} に指定するノードの名前が無い"),
                    $node->min_assignees ?? throw new LogicException("ノード {$node->id} に人数の最小が無い"),
                    $node->max_assignees ?? throw new LogicException("ノード {$node->id} に人数の最大が無い"),
                ),
            };
        }

        return $targets;
    }

    /**
     * @param  list<mixed>  $values  列の値(null を除き、重ねない)
     * @return list<int>
     */
    private static function idsOf(array $values): array
    {
        $present = array_filter($values, fn (mixed $value) => $value !== null);

        return array_values(array_unique(array_map('intval', $present)));
    }

    /**
     * @param  array<int, array{code: string, name: string|null}>  $posts
     */
    private function relative(WorkflowRouteNode|WorkflowCaseNode $node, array $posts): StepTarget
    {
        $postId = $node->company_post_id === null ? null : (int) $node->company_post_id;

        return StepTarget::relative(
            $node->relative_base ?? throw new LogicException("ノード {$node->id} に起点が無い"),
            $node->relative_scope ?? throw new LogicException("ノード {$node->id} に組織の範囲が無い"),
            $postId,
            $postId === null ? null : ($posts[$postId]['name'] ?? "#{$postId}"),
        );
    }
}
