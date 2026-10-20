<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowRouteNode;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 処理期限の日数と確認する人の制約(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 2.3 節・3.2 節、同じ文書群の README.md の 7 節):
 * 期限は 0〜99 で承認・横配置・縦配置だけ、確認する人は申請・承認だけで用途 selection の対象者、展開の行は確認する人を持たない。
 * ルートのノードと、それを写した案件のノードに同じ式を置くので、両方の表で同じ行を確かめる。行ごとに 1 つの制約だけに反する値にする。
 */
final class WorkflowNodeDeadlineConfirmTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** ノード(ルートのノードと、それを写した案件のノード)のモデル */
    private const NODE_MODELS = [WorkflowRouteNode::class, WorkflowCaseNode::class];

    /**
     * 拒む行: 名前 → [factory の状態(null = 既定), 状態の種類, 上書きする列, 拒む制約(表の名前の後ろ)]。
     *
     * @return array<string, array{string|null, WorkflowNodeKind|null, array<string, mixed>, string}>
     */
    private static function rejectedRows(): array
    {
        $approve = ['position' => 2];
        $confirm = fn (): int => self::subjectGroup(SubjectGroupPurpose::Selection);
        $policy = fn (): int => self::subjectGroup(SubjectGroupPurpose::Policy);

        return [
            '期限の日数 -1' => [null, null, [...$approve, 'deadline_days' => -1], 'deadline_days_check'],
            '期限の日数 100' => [null, null, [...$approve, 'deadline_days' => 100], 'deadline_days_check'],
            '申請のノードが期限を持つ' => [null, null, ['position' => 1, 'deadline_days' => 1], 'deadline_days_kind_check'],
            '分岐のノードが期限を持つ' => ['branchStart', null, ['deadline_days' => 1], 'deadline_days_kind_check'],
            '縦配置が確認する人を持つ' => [
                'designated', WorkflowNodeKind::Parallel, ['confirm_authz_subject_group_id' => $confirm],
                'confirm_authz_subject_group_id_kind_check',
            ],
            '分岐のノードが確認する人を持つ' => [
                'branchStart', null, ['confirm_authz_subject_group_id' => $confirm],
                'confirm_authz_subject_group_id_kind_check',
            ],
            '確認する人が用途 selection でない対象者' => [
                null, null, [...$approve, 'confirm_authz_subject_group_id' => $policy],
                'confirm_authz_subject_group_id_purpose_foreign',
            ],
        ];
    }

    /**
     * 受ける行: 名前 → [factory の状態(null = 既定), 状態の種類, 上書きする列]。
     *
     * @return array<string, array{string|null, WorkflowNodeKind|null, array<string, mixed>}>
     */
    private static function acceptedRows(): array
    {
        $confirm = fn (): int => self::subjectGroup(SubjectGroupPurpose::Selection);

        return [
            '承認の期限 0' => [null, null, ['position' => 2, 'deadline_days' => 0]],
            '承認の期限 99' => [null, null, ['position' => 2, 'deadline_days' => 99]],
            '横配置の期限 0' => ['designated', WorkflowNodeKind::Serial, ['deadline_days' => 0]],
            '横配置の期限 99' => ['designated', WorkflowNodeKind::Serial, ['deadline_days' => 99]],
            '縦配置の期限 0' => ['designated', WorkflowNodeKind::Parallel, ['deadline_days' => 0]],
            '縦配置の期限 99' => ['designated', WorkflowNodeKind::Parallel, ['deadline_days' => 99]],
            '申請の確認する人' => [null, null, ['position' => 1, 'confirm_authz_subject_group_id' => $confirm]],
            '承認の確認する人' => [null, null, ['position' => 2, 'confirm_authz_subject_group_id' => $confirm]],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rejected(): array
    {
        return self::forEachModel(self::rejectedRows());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function accepted(): array
    {
        return self::forEachModel(self::acceptedRows());
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('rejected')]
    public function test_rejects_a_deadline_or_confirmation_that_does_not_match(
        string $model, ?string $state, ?WorkflowNodeKind $kind, array $overrides, string $constraint,
    ): void {
        $table = (new $model)->getTable();

        $this->assertRejectedBy(
            "{$table}_{$constraint}",
            fn () => $this->factory($model, $state, $kind)->createOne($this->evaluated($overrides)),
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('accepted')]
    public function test_accepts_a_deadline_or_confirmation_that_matches(
        string $model, ?string $state, ?WorkflowNodeKind $kind, array $overrides,
    ): void {
        $row = $this->factory($model, $state, $kind)->createOne($this->evaluated($overrides));

        $this->assertModelExists($row);
    }

    public function test_rejects_a_confirmation_on_an_expansion_row(): void
    {
        $this->assertRejectedBy(
            'workflow_case_nodes_parent_workflow_case_node_id_confirm_authz_subject_group_id_check',
            fn () => WorkflowCaseNode::factory()->child()->confirming()->createOne(),
        );
    }

    public function test_accepts_a_deadline_copied_to_an_expansion_row(): void
    {
        $child = WorkflowCaseNode::factory()->child()->createOne(['deadline_days' => 3]);

        $this->assertSame(3, $child->refresh()->deadline_days);
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

    private static function subjectGroup(SubjectGroupPurpose $purpose): int
    {
        return AuthzSubjectGroup::factory()->createOne(['purpose' => $purpose])->id;
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

    /**
     * 上書きする列の値(Closure は書く直前に評価する)。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function evaluated(array $overrides): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof Closure ? $value() : $value, $overrides);
    }
}
