<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowRouteNode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 添付の可否の列(docs の 設計/ワークフロー/申請の周辺/データモデル.md の 2.1 節・2.2 節・3.1 節・3.4 節・7 節): 列の既定
 * (フローは使う、ノードは追加できる)、添付を追加できないを持てるのは承認・横配置・縦配置だけ(ルートのノードと、それを写した
 * 案件のノードの両方)、案件の写しを持たないのは一時保存だけ。
 */
final class WorkflowAttachmentSettingsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** ノード(ルートのノードと、それを写した案件のノード)のモデル */
    private const NODE_MODELS = [WorkflowRouteNode::class, WorkflowCaseNode::class];

    private const CASE_CHECK = 'workflow_cases_state_is_attachment_enabled_check';

    /** 指定なし(分岐・同期)のノードの列 */
    private const ROUTING = ['position' => 2, 'target_kind' => null, 'authz_subject_group_id' => null];

    /**
     * 種類ごとの行: 名前 → [factory の状態(null = 既定), 状態の種類, 上書きする列]。
     *
     * @return array<string, array{string|null, WorkflowNodeKind|null, array<string, mixed>}>
     */
    private static function rejectedKinds(): array
    {
        return [
            '申請' => [null, null, ['position' => 1]],
            '分岐開始' => ['branchStart', null, []],
            '分岐終了' => [null, null, [...self::ROUTING, 'kind' => WorkflowNodeKind::BranchEnd]],
            '同期開始' => ['branchStart', null, ['kind' => WorkflowNodeKind::SyncStart]],
            '同期終了' => [null, null, [...self::ROUTING, 'kind' => WorkflowNodeKind::SyncEnd]],
        ];
    }

    /**
     * @return array<string, array{string|null, WorkflowNodeKind|null, array<string, mixed>}>
     */
    private static function acceptedKinds(): array
    {
        return [
            '承認' => [null, null, ['position' => 2]],
            '横配置' => ['designated', WorkflowNodeKind::Serial, []],
            '縦配置' => ['designated', WorkflowNodeKind::Parallel, []],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rejected(): array
    {
        return self::forEachModel(self::rejectedKinds());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function accepted(): array
    {
        return self::forEachModel(self::acceptedKinds());
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function nodeModels(): array
    {
        return [
            'WorkflowRouteNode' => [WorkflowRouteNode::class],
            'WorkflowCaseNode' => [WorkflowCaseNode::class],
        ];
    }

    public function test_a_flow_uses_attachments_by_default(): void
    {
        $this->assertTrue(WorkflowFlow::factory()->createOne()->refresh()->is_attachment_enabled);
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('nodeModels')]
    public function test_a_node_lets_attachments_be_added_by_default(string $model): void
    {
        $node = Factory::factoryForModel($model)->createOne()->refresh();

        $this->assertFalse($node->getAttribute('prevents_attachment_add'));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('rejected')]
    public function test_rejects_preventing_attachments_on_a_node_that_is_not_an_approval(
        string $model, ?string $state, ?WorkflowNodeKind $kind, array $overrides,
    ): void {
        $table = (new $model)->getTable();

        $this->assertRejectedBy(
            "{$table}_prevents_attachment_add_kind_check",
            fn () => $this->factory($model, $state, $kind)
                ->createOne([...$overrides, 'prevents_attachment_add' => true]),
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('accepted')]
    public function test_accepts_preventing_attachments_on_an_approval(
        string $model, ?string $state, ?WorkflowNodeKind $kind, array $overrides,
    ): void {
        $row = $this->factory($model, $state, $kind)->createOne([...$overrides, 'prevents_attachment_add' => true]);

        $this->assertTrue($row->refresh()->getAttribute('prevents_attachment_add'));
    }

    public function test_accepts_preventing_attachments_copied_to_an_expansion_row(): void
    {
        $child = WorkflowCaseNode::factory()->child()->createOne(['prevents_attachment_add' => true]);

        $this->assertTrue($child->refresh()->prevents_attachment_add);
    }

    public function test_rejects_a_copy_on_a_draft(): void
    {
        $this->assertRejectedBy(
            self::CASE_CHECK,
            fn () => WorkflowCase::factory()->draft()->createOne(['is_attachment_enabled' => true]),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function appliedStates(): array
    {
        return ['未完了' => ['active'], '完了' => ['completed']];
    }

    #[DataProvider('appliedStates')]
    public function test_rejects_an_applied_case_without_a_copy(string $state): void
    {
        $this->assertRejectedBy(
            self::CASE_CHECK,
            fn () => WorkflowCase::factory()->{$state}()->createOne(['is_attachment_enabled' => null]),
        );
    }

    public function test_accepts_a_draft_without_a_copy_and_an_applied_case_with_either_value(): void
    {
        $cases = [
            WorkflowCase::factory()->draft()->createOne(),
            WorkflowCase::factory()->active()->createOne(['is_attachment_enabled' => false]),
            WorkflowCase::factory()->completed()->createOne(['is_attachment_enabled' => true]),
        ];

        $this->assertSame(
            [null, false, true],
            array_map(fn (WorkflowCase $case): ?bool => $case->refresh()->is_attachment_enabled, $cases),
        );
    }

    /**
     * 行ごとに、ルートのノードと案件のノードの 2 つの場合にする(先頭にモデル)。
     *
     * @param  array<string, array<int, mixed>>  $rows
     * @return array<string, array<int, mixed>>
     */
    private static function forEachModel(array $rows): array
    {
        $cases = [];

        foreach (self::NODE_MODELS as $model) {
            foreach ($rows as $label => $row) {
                $cases[class_basename($model).' '.$label] = [$model, ...$row];
            }
        }

        return $cases;
    }

    /**
     * @param  class-string<Model>  $model
     * @return Factory<Model>
     */
    private function factory(string $model, ?string $state, ?WorkflowNodeKind $kind): Factory
    {
        $factory = Factory::factoryForModel($model);

        return match (true) {
            $state === null => $factory,
            $kind !== null => $factory->{$state}($kind),
            default => $factory->{$state}(),
        };
    }
}
