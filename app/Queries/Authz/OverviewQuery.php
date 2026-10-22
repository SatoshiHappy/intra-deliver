<?php

namespace App\Queries\Authz;

use App\Enums\Authz\AuthorizationResult;
use App\Enums\Authz\ServiceAction;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;

/**
 * 認可設定の画面の格子(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2 節): 1 格子 = 1 リソースタイプ。
 * 行 = {@see OverviewTree}、列 = {@see OverviewSubjects} × タイプのアクション(ResourceTypeCatalog の並び)、セル = {@see OverviewSettings}。
 * 判定 = タイプのリソースを持つ行について、アクションごとの判定の口 {@see AuthorizationQuery::explain()} の結果(リソースを持たない行は null)。
 * 行 × アクションの判定は {@see AuthorizationQuery::explainEach()} でまとめて求める(問い合わせの数を行の数に比例させない)。
 * 閉塞の値は読み直さない(判定の結果だけ)。返す形の画面の型は resources/js/types/authz.ts の AuthzGrid。
 *
 * @phpstan-import-type GridSubject from OverviewSubjects
 * @phpstan-import-type GridSetting from OverviewSettings
 *
 * @phpstan-type GridJudgement array{action: string, result: string, decidingSubjectGroupIds: list<int>}
 * @phpstan-type GridRow array{resourceGroupId: int, parentId: int|null, depth: int, code: string, name: string|null, settings: list<GridSetting>, judgements: list<GridJudgement>|null}
 * @phpstan-type Grid array{resourceType: string, actions: list<string>, subjects: list<GridSubject>, rows: list<GridRow>}
 */
class OverviewQuery
{
    public function __construct(
        private AuthorizationQuery $authorization,
        private ResourceTypeCatalog $catalog,
        private OverviewTree $tree,
        private OverviewSubjects $subjects,
        private OverviewSettings $settings,
    ) {}

    /**
     * リソースの種類の選択肢(ResourceTypeCatalog の並び)。
     *
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return array_keys($this->catalog->actionsByResourceType());
    }

    /**
     * クエリ type の値から格子のリソースタイプ。列挙にあればそれ、無い・列挙に無い値は画面・処理(検証のエラーにしない = 2.1)。
     */
    public function resourceTypeOf(?string $resourceType): string
    {
        return $resourceType !== null && $this->catalog->hasResourceType($resourceType) ? $resourceType : ServiceAction::RESOURCE_TYPE;
    }

    /**
     * 判定を求める者が d に、リソースタイプの格子。
     *
     * @param  bool  $withFingerprints  列に件数と指紋を入れるか(列を削除できる人だけ。偽なら null)
     * @return Grid
     */
    public function gridOn(Requester $requester, string $resourceType, DateTimeInterface $date, string $localeCode, bool $withFingerprints): array
    {
        $actions = $this->catalog->actionsByResourceType()[$resourceType] ?? [];
        $subjects = $this->subjects->subjectsOn($requester, $date, $localeCode, $withFingerprints);
        $subjectGroupIds = array_column($subjects, 'id');
        $nodes = $this->tree->nodesOf($resourceType, $localeCode);
        $settings = $this->settings->settingsOf($resourceType, $nodes, $subjectGroupIds, $actions);
        $judgedIds = array_map(fn (OverviewTreeNode $node) => $node->resourceGroupId, array_values(array_filter(
            $nodes,
            fn (OverviewTreeNode $node) => $node->holdsResource,
        )));
        $explanations = $this->authorization->explainEach($requester, $judgedIds, $actions, $date);

        return [
            'resourceType' => $resourceType,
            'actions' => $actions,
            'subjects' => $subjects,
            'rows' => array_map(fn (OverviewTreeNode $node) => [
                'resourceGroupId' => $node->resourceGroupId,
                'parentId' => $node->parentId,
                'depth' => $node->depth,
                'code' => $node->code,
                'name' => $node->name,
                'settings' => $settings[$node->resourceGroupId] ?? [],
                'judgements' => $node->holdsResource
                    ? $this->judgements($actions, $explanations[$node->resourceGroupId], $subjectGroupIds)
                    : null,
            ], $nodes),
        ];
    }

    /**
     * 行のアクションごとの判定。効いた対象者は列の並び(列に無い対象者は捨てず、列の後に id の順)。
     *
     * @param  list<string>  $actions
     * @param  array<string, AuthorizationExplanation>  $explanations  アクション → 判定
     * @param  list<int>  $subjectGroupIds  列の対象者(列の並び)
     * @return list<GridJudgement>
     */
    private function judgements(array $actions, array $explanations, array $subjectGroupIds): array
    {
        return array_map(function (string $action) use ($explanations, $subjectGroupIds) {
            $explanation = $explanations[$action];
            $deciding = array_map(fn (EffectivePolicy $policy) => $policy->subjectGroupId, $explanation->decidingPolicies());
            $outside = array_diff($deciding, $subjectGroupIds);
            sort($outside);

            return [
                'action' => $action,
                'result' => $this->resultValue($explanation->result),
                'decidingSubjectGroupIds' => [...array_values(array_intersect($subjectGroupIds, $deciding)), ...$outside],
            ];
        }, $actions);
    }

    /**
     * 判定の結果の、画面に渡す値(列挙は値を持たないまま変えない = 認可設定の画面.md 1.2)。
     */
    private function resultValue(AuthorizationResult $result): string
    {
        return match ($result) {
            AuthorizationResult::Permit => 'permit',
            AuthorizationResult::Deny => 'deny',
            AuthorizationResult::Block => 'block',
        };
    }
}
