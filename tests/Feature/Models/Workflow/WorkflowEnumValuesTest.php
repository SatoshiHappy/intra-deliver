<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowProxy;
use App\Models\Workflow\WorkflowRouteNode;
use BackedEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 決まった値の一覧の列と列挙の一致(docs の 設計/ワークフロー/データモデル/README.md 1 節: 値の正本は列挙 App\Enums\Workflow で、検査制約と同じ値を持つ。
 * 直列化と検査.md 6 節の「決まった値の一覧の外の値を拒む」): 列ごとの検査制約 <表>_<列>_check の値の一覧が列挙の値と同じ、列挙のどの値の行も入り
 * (キャストで列挙に読み戻る)、一覧の外の値の行は検査制約に拒まれる。段 3a で足した列(ルートの深さ/データモデル/README.md 1 節の決まった値の一覧)も同じ。
 * 案件の運用で足した値と列(設計/ワークフロー/案件の運用/データモデル/README.md の 1 節)も同じ。
 */
final class WorkflowEnumValuesTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** 表 → 列 → 列挙 */
    private const COLUMNS = [
        'workflow_route_nodes' => ['kind' => WorkflowNodeKind::class, ...self::NODE_SETTINGS],
        'workflow_cases' => ['state' => WorkflowCaseState::class, 'completion_reason' => WorkflowCompletionReason::class],
        'workflow_case_nodes' => ['kind' => WorkflowNodeKind::class, 'state' => WorkflowNodeState::class, ...self::NODE_SETTINGS],
        'workflow_case_histories' => ['action' => WorkflowAction::class],
        'workflow_proxies' => ['kind' => WorkflowProxyKind::class],
    ];

    /** ノードの設定の決まった値の列(ルートのノードと案件のノードで同じ) */
    private const NODE_SETTINGS = [
        'target_kind' => WorkflowTargetKind::class,
        'relative_base' => WorkflowRelativeBase::class,
        'relative_scope' => WorkflowRelativeScope::class,
        'decision_rule' => WorkflowDecisionRule::class,
    ];

    /**
     * 一覧の外の値の行で、ほかの検査制約(名前順で先に評価される)に先に拒まれないように変える列(列 → 上書き)。
     * 指定の種類は、承認のノードで対象者を持たない行にする(申請のノードは対象者で指定だけ = kind_target_kind_check、対象者で指定でない指定の種類が
     * 対象者を持つ = target_kind_authz_subject_group_id_check が先に拒む)。
     */
    private const OUTSIDE_VALUE_OVERRIDES = ['target_kind' => ['position' => 2, 'kind' => 'approve', 'authz_subject_group_id' => null]];

    /** 表 → モデル */
    private const MODELS = [
        'workflow_route_nodes' => WorkflowRouteNode::class,
        'workflow_cases' => WorkflowCase::class,
        'workflow_case_nodes' => WorkflowCaseNode::class,
        'workflow_case_histories' => WorkflowCaseHistory::class,
        'workflow_proxies' => WorkflowProxy::class,
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function columns(): array
    {
        $cases = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $cases["{$table}.{$column}"] = [$table, $column];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string, BackedEnum}>
     */
    public static function values(): array
    {
        $cases = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $enum) {
                foreach ($enum::cases() as $value) {
                    $cases["{$table}.{$column} {$value->value}"] = [$table, $column, $value];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('columns')]
    public function test_the_check_constraint_lists_exactly_the_enum_values(string $table, string $column): void
    {
        $definition = DB::scalar('select pg_get_constraintdef(oid) from pg_constraint where conname = ?', ["{$table}_{$column}_check"]);
        preg_match_all("/'([^']*)'::character varying/", (string) $definition, $matches);
        $expected = array_map(fn (BackedEnum $value): string => (string) $value->value, self::COLUMNS[$table][$column]::cases());
        $actual = $matches[1];
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    #[DataProvider('values')]
    public function test_accepts_every_enum_value(string $table, string $column, BackedEnum $value): void
    {
        $row = $this->rowWith($table, $value);

        $this->assertSame($value, $row->refresh()->getAttribute($column));
    }

    /**
     * workflow_case_nodes.state は、同じ表の検査制約が名前順で評価され kind_state_check(2 つの一覧の和 = state_check の一覧)が必ず先に拒むので、
     * 列の制約そのものの拒否は行の書き込みでは観測できない。一覧の中身は test_the_check_constraint_lists_exactly_the_enum_values が守る。
     */
    #[DataProvider('columns')]
    public function test_rejects_a_value_outside_the_list(string $table, string $column): void
    {
        $row = [...Factory::factoryForModel(self::MODELS[$table])->makeOne()->getAttributes(), ...(self::OUTSIDE_VALUE_OVERRIDES[$column] ?? []), $column => 'unknown'];
        $constraint = $table === 'workflow_case_nodes' && $column === 'state' ? 'workflow_case_nodes_kind_state_check' : "{$table}_{$column}_check";

        $this->assertRejectedBy($constraint, fn () => DB::table($table)->insert($row));
    }

    /**
     * 列に値を持つ、ほかの制約を通る行(値ごとに、その値と組になる列を factory で決める)。
     */
    private function rowWith(string $table, BackedEnum $value): Model
    {
        return match (true) {
            $value instanceof WorkflowNodeKind => $this->nodeOfKind($table, $value),
            $value instanceof WorkflowTargetKind => $this->nodeFactory($table, ['fixed' => null, 'relative' => 'relative', 'designated' => 'designated'][$value->value])->createOne(),
            $value instanceof WorkflowRelativeBase, $value instanceof WorkflowRelativeScope => $this->nodeFactory($table, 'relative')->createOne([$value instanceof WorkflowRelativeBase ? 'relative_base' : 'relative_scope' => $value]),
            $value instanceof WorkflowDecisionRule => $this->nodeFactory($table, 'designated', WorkflowNodeKind::Parallel)->createOne(['decision_rule' => $value, 'decision_value' => $value === WorkflowDecisionRule::All ? null : 1]),
            $value instanceof WorkflowNodeState => $this->caseNodeIn($value),
            $value instanceof WorkflowCaseState => WorkflowCase::factory()->{$value->value}()->createOne(),
            $value instanceof WorkflowCompletionReason => WorkflowCase::factory()->completed()->createOne(['completion_reason' => $value]),
            $value instanceof WorkflowAction => $this->historyOf($value),
            $value instanceof WorkflowProxyKind => WorkflowProxy::factory()->createOne(['kind' => $value]),
            default => $this->fail('列挙の行の作り方が無い: '.$value::class),
        };
    }

    private function caseNodeIn(WorkflowNodeState $state): WorkflowCaseNode
    {
        return match ($state) {
            WorkflowNodeState::Done => WorkflowCaseNode::factory()->done()->createOne(),
            WorkflowNodeState::Waiting, WorkflowNodeState::Skipped, WorkflowNodeState::Hold => WorkflowCaseNode::factory()->createOne(['position' => 2, 'state' => $state]),
            WorkflowNodeState::Running, WorkflowNodeState::Passed => WorkflowCaseNode::factory()->designated(WorkflowNodeKind::Serial)->createOne(['state' => $state]),
            default => WorkflowCaseNode::factory()->createOne(['position' => 1, 'state' => $state]),
        };
    }

    /**
     * その種類のノード(種類に合う処理対象者の指定・対の終了を持つ)。
     */
    private function nodeOfKind(string $table, WorkflowNodeKind $kind): Model
    {
        return match (true) {
            $kind === WorkflowNodeKind::Apply => $this->nodeFactory($table, null)->createOne(['position' => 1]),
            $kind === WorkflowNodeKind::Approve => $this->nodeFactory($table, null)->createOne(['position' => 2]),
            in_array($kind, WorkflowNodeKind::expanding(), true) => $this->nodeFactory($table, 'designated', $kind)->createOne(),
            in_array($kind, WorkflowNodeKind::opening(), true) => $this->nodeFactory($table, 'branchStart')->createOne(['kind' => $kind]),
            default => $this->nodeFactory($table, null)->createOne(['position' => 2, 'kind' => $kind, 'target_kind' => null, 'authz_subject_group_id' => null]),
        };
    }

    /**
     * ノードの表の factory(状態つき)。
     *
     * @return Factory<Model>
     */
    private function nodeFactory(string $table, ?string $state, ?WorkflowNodeKind $kind = null): Factory
    {
        $factory = Factory::factoryForModel(self::MODELS[$table]);

        return match (true) {
            $state === null => $factory,
            $kind !== null => $factory->{$state}($kind),
            default => $factory->{$state}(),
        };
    }

    private function historyOf(WorkflowAction $action): WorkflowCaseHistory
    {
        return in_array($action, [WorkflowAction::SendBack, WorkflowAction::MoveBack], true)
            ? WorkflowCaseHistory::factory()->sendBack()->createOne(['action' => $action])
            : WorkflowCaseHistory::factory()->createOne(['action' => $action]);
    }
}
