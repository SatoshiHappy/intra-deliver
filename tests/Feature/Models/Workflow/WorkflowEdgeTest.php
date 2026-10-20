<?php

namespace Tests\Feature\Models\Workflow;

use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseSendBack;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 辺と差戻しできる先の制約(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.4・2.5、案件の表.md 3.3・3.4、直列化と検査.md 7 節の「辺」と
 * 実装へ送られた「案件の辺と写した差戻しできる先の制約」): 自分への辺、名前と条件の組、空の名前、配列の条件、負の並び、同じ 2 つのノードの間の 2 本目、
 * 別の版(案件)のノードを指す端・差戻し元・先を拒む。案件の辺の分岐の評価の結果は条件を持つ辺だけ。ルートと、それを写した案件で同じ形を確かめる。
 */
final class WorkflowEdgeTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** 辺のモデル → [ノードのモデル, 範囲の列, ノードの列の中の語] */
    private const EDGES = [
        WorkflowRouteEdge::class => [WorkflowRouteNode::class, 'workflow_flow_version_id', 'route'],
        WorkflowCaseEdge::class => [WorkflowCaseNode::class, 'workflow_case_id', 'case'],
    ];

    /** 差戻しできる先のモデル → [ノードのモデル, 範囲の列, ノードの列の中の語] */
    private const SEND_BACKS = [
        WorkflowRouteSendBack::class => [WorkflowRouteNode::class, 'workflow_flow_version_id', 'route'],
        WorkflowCaseSendBack::class => [WorkflowCaseNode::class, 'workflow_case_id', 'case'],
    ];

    /**
     * @return array<string, array{class-string<Model>, array<string, mixed>, string}>
     */
    public static function rejectedEdges(): array
    {
        $rows = [
            '名前だけ' => [['label' => '予算 300 万円以上'], 'label_condition_check'],
            '条件だけ' => [['condition' => ['all' => []]], 'label_condition_check'],
            '空の名前' => [['label' => '', 'condition' => ['all' => []]], 'label_check'],
            '配列の条件' => [['label' => '何もしない', 'condition' => ['a', 'b']], 'condition_check'],
            '負の並び' => [['sort_key' => -1], 'sort_key_check'],
        ];
        $cases = [];

        foreach (array_keys(self::EDGES) as $model) {
            foreach ($rows as $label => [$overrides, $constraint]) {
                $cases[class_basename($model).' '.$label] = [$model, $overrides, $constraint];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function edgeModels(): array
    {
        return self::keyed(array_keys(self::EDGES));
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function sendBackModels(): array
    {
        return self::keyed(array_keys(self::SEND_BACKS));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('rejectedEdges')]
    public function test_rejects_an_edge_whose_columns_do_not_match(string $model, array $overrides, string $constraint): void
    {
        $this->assertRejectedBy((new $model)->getTable()."_{$constraint}", fn () => Factory::factoryForModel($model)->createOne($overrides));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('edgeModels')]
    public function test_accepts_a_conditional_edge(string $model): void
    {
        $edge = ($model === WorkflowRouteEdge::class ? WorkflowRouteEdge::factory() : WorkflowCaseEdge::factory())->conditional()->createOne();

        $this->assertEquals(['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]], $edge->refresh()->getAttribute('condition'));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('edgeModels')]
    public function test_rejects_an_edge_to_its_own_node(string $model): void
    {
        $edge = Factory::factoryForModel($model)->createOne();
        $node = self::EDGES[$model][2];

        $this->assertRejectedBy(
            "{$edge->getTable()}_to_workflow_{$node}_node_id_from_workflow_{$node}_node_id_check",
            fn () => $edge->update(["to_workflow_{$node}_node_id" => $edge->getAttribute("from_workflow_{$node}_node_id")]),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('edgeModels')]
    public function test_rejects_a_second_edge_between_the_same_nodes(string $model): void
    {
        $edge = Factory::factoryForModel($model)->createOne();
        [, $scope, $node] = self::EDGES[$model];

        $this->assertRejectedBy(
            "{$edge->getTable()}_from_workflow_{$node}_node_id_to_workflow_{$node}_node_id_unique",
            fn () => Factory::factoryForModel($model)->createOne($edge->only([$scope, "from_workflow_{$node}_node_id", "to_workflow_{$node}_node_id"])),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('edgeModels')]
    public function test_rejects_an_edge_to_a_node_outside_the_scope(string $model): void
    {
        [$nodeModel, $scope, $node] = self::EDGES[$model];
        $elsewhere = Factory::factoryForModel($nodeModel)->createOne();

        foreach (['from', 'to'] as $end) {
            $edge = Factory::factoryForModel($model)->createOne();
            $this->assertRejectedBy(
                "{$edge->getTable()}_{$scope}_{$end}_workflow_{$node}_node_id_foreign",
                fn () => $edge->update(["{$end}_workflow_{$node}_node_id" => $elsewhere->id]),
            );
        }
    }

    public function test_rejects_an_evaluation_result_on_an_edge_without_a_condition(): void
    {
        $this->assertRejectedBy('workflow_case_edges_taken_condition_check', fn () => WorkflowCaseEdge::factory()->createOne(['taken' => true]));
    }

    public function test_accepts_an_evaluation_result_on_a_conditional_edge(): void
    {
        $edge = WorkflowCaseEdge::factory()->conditional()->createOne(['taken' => false]);

        $this->assertFalse($edge->refresh()->taken);
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('sendBackModels')]
    public function test_rejects_the_same_send_back_target_twice(string $model): void
    {
        $sendBack = Factory::factoryForModel($model)->createOne();
        [, $scope, $node] = self::SEND_BACKS[$model];

        $this->assertRejectedBy(
            "{$sendBack->getTable()}_workflow_{$node}_node_id_target_workflow_{$node}_node_id_unique",
            fn () => Factory::factoryForModel($model)->createOne($sendBack->only([$scope, "workflow_{$node}_node_id", "target_workflow_{$node}_node_id"])),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('sendBackModels')]
    public function test_rejects_a_send_back_from_or_to_a_node_outside_the_scope(string $model): void
    {
        [$nodeModel, $scope, $node] = self::SEND_BACKS[$model];
        $elsewhere = Factory::factoryForModel($nodeModel)->createOne();

        foreach (["workflow_{$node}_node_id", "target_workflow_{$node}_node_id"] as $column) {
            $sendBack = Factory::factoryForModel($model)->createOne();
            $this->assertRejectedBy(
                "{$sendBack->getTable()}_{$scope}_{$column}_foreign",
                fn () => $sendBack->update([$column => $elsewhere->id]),
            );
        }
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return array<string, array{class-string<Model>}>
     */
    private static function keyed(array $models): array
    {
        return array_combine(array_map(class_basename(...), $models), array_map(fn (string $model): array => [$model], $models));
    }
}
