<?php

namespace Database\Factories\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 状態ごとの列の組は docs の 設計/ワークフロー/データモデル/表.md 2.5 の表と、ルートの深さ/データモデル/案件の表.md 3.1 のとおり(ノード・辺・履歴は作らない)。
 * 申請した案件の申請基準日は今日(渡せば渡した日)、版はフローのその日に有効な版(申請に使う版の規則 = 定義の表.md 2.1。読み取りの口
 * App\Queries\Workflow\EffectiveFlowVersions と同じ条件。factory は読み取りの層に依存できないので、同じ有効の定義(HasPeriod の effectiveOn)で引く)。
 * フローに版が 1 つも無ければ、2000-01-01 から上限なしの版を作る。版があってその日に有効な版が無ければ例外。
 * 申請した案件の「添付を使う」の写しは、フローの既定と同じ true(一時保存は NULL = 設計/ワークフロー/申請の周辺/データモデル.md の 3.4 節)。
 *
 * @extends Factory<WorkflowCase>
 */
class WorkflowCaseFactory extends Factory
{
    /**
     * Define the model's default state(一時保存).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_flow_id' => WorkflowFlow::factory(),
            'user_id' => User::factory(),
            ...$this->draftColumns(),
            'revision' => 0,
        ];
    }

    /**
     * 一時保存にする(案件番号・案件名・担当組織・レコード・申請日時を持たず、一時保存の入力を持つ)。
     */
    public function draft(): static
    {
        return $this->state(fn (): array => $this->draftColumns());
    }

    /**
     * 未完了にする(案件番号をシーケンスから採り、フローのコンテンツの業務モジュールにレコードを作る。担当組織を持つ)。
     * 担当組織は申請者の所属と無関係に作る(DB の制約のテスト用。
     * 設計/ワークフロー/データモデル/表.md 2.5 の「申請の確定の時の申請者のカレント組織」は業務処理が決める)。
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'state' => WorkflowCaseState::Active,
            'completion_reason' => null,
            'case_number' => fn (): mixed => DB::scalar("select nextval('workflow_case_number_seq')"),
            'name' => fake()->words(3, true),
            'department_id' => Department::factory(),
            'record_id' => fn (array $attributes) => Record::factory()->state([
                'record_module_id' => WorkflowFlow::query()->whereKey($attributes['workflow_flow_id'])->sole()->workflowContent()->sole()->record_module_id,
            ]),
            'draft_input' => null,
            'applied_at' => now(),
            'completed_at' => null,
            'base_date' => now()->toDateString(),
            'workflow_flow_version_id' => fn (array $attributes): int => $this->versionOn($attributes['workflow_flow_id'], CarbonImmutable::parse($attributes['base_date'] ?? 'today')),
            'is_attachment_enabled' => fn (array $attributes): bool => WorkflowFlow::query()
                ->whereKey($attributes['workflow_flow_id'])->sole()->is_attachment_enabled,
        ]);
    }

    /**
     * 完了(承認)にする(未完了の列に、完了の理由と完了の日時を足す)。
     */
    public function completed(): static
    {
        return $this->active()->state(fn (): array => [
            'state' => WorkflowCaseState::Completed,
            'completion_reason' => WorkflowCompletionReason::Approved,
            'completed_at' => now(),
        ]);
    }

    /**
     * フローの申請基準日に有効な版(フローに版が無ければ作り、作った版も申請基準日で確かめる)。
     *
     * @throws LogicException 申請基準日に有効な版が無いとき(申請に使う版の規則に反する案件を作らない)
     */
    private function versionOn(int $flowId, CarbonImmutable $baseDate): int
    {
        if (! WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->exists()) {
            WorkflowFlowVersion::factory()->createOne(['workflow_flow_id' => $flowId]);
        }

        return WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->effectiveOn($baseDate)->value('id')
            ?? throw new LogicException("フロー {$flowId} に申請基準日 {$baseDate->toDateString()} に有効な版が無い");
    }

    /**
     * 一時保存の列(一時保存の入力は 設計/ワークフロー/業務データとの接続/README.md 2 節の形の空のもの)。
     *
     * @return array<string, mixed>
     */
    private function draftColumns(): array
    {
        return [
            'state' => WorkflowCaseState::Draft,
            'completion_reason' => null,
            'case_number' => null,
            'name' => null,
            'department_id' => null,
            'record_id' => null,
            'draft_input' => ['name' => null, 'comment' => null, 'values' => [], 'details' => []],
            'applied_at' => null,
            'completed_at' => null,
            'base_date' => null,
            'workflow_flow_version_id' => null,
            'is_attachment_enabled' => null,
        ];
    }
}
