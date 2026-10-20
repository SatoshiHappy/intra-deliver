<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Workflow\WorkflowCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 段 3a の入口の封筒に足した入力(docs の 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 13 節の表の最後の行「入口の封筒の誤り」= `node_id`・`assignments`・
 * `base_date`)。欠け・型の誤りはどれも 422(該当のキー)で 500 にならず、何も変わらない。数でない `assignments` のキーは封筒を通り、業務処理が 422 `assignments`
 * (指定するステップの欠け・余り = D4)にする。初期データの案件 WF-000002(課長 u0002 の承認待ち)と u0001 の一時保存を使う。
 */
final class WorkflowRouteDepthEnvelopeTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    /**
     * @return array<string, array{string}>
     */
    public static function nodeActions(): array
    {
        return ['approve' => ['approve'], 'approve early' => ['approve-early'], 'reject' => ['reject'], 'send back' => ['send-back'], 'pull back' => ['pull-back']];
    }

    #[DataProvider('nodeActions')]
    public function test_a_missing_or_malformed_node_id_is_422(string $action): void
    {
        $this->actingAsUser('u0002');
        $case = $this->caseNumbered(2);
        $base = ['revision' => $this->revisionOf($case), 'comment' => null, 'send_back_node_id' => $this->currentNodeIdOf($case)];

        foreach ([[], ['node_id' => 'x'], ['node_id' => [1]], ['node_id' => '1.5']] as $nodeId) {
            $this->postJson("/workflow/cases/{$case}/{$action}", [...$base, ...$nodeId])->assertUnprocessable()->assertJsonValidationErrors(['node_id']);
        }

        $this->assertSame($base['revision'], $this->revisionOf($case));
    }

    public function test_malformed_assignments_are_422_and_a_non_numeric_key_is_rejected_by_the_operation(): void
    {
        $this->actingAsUser('u0002');
        $case = $this->caseNumbered(2);
        $base = ['revision' => $this->revisionOf($case), 'comment' => null, 'node_id' => $this->currentNodeIdOf($case)];
        $approve = fn (mixed $assignments) => $this->postJson("/workflow/cases/{$case}/approve", [...$base, 'assignments' => $assignments]);

        $approve('x')->assertUnprocessable()->assertJsonValidationErrors(['assignments']);
        $approve([$base['node_id'] => 'x'])->assertUnprocessable()->assertJsonValidationErrors(["assignments.{$base['node_id']}"]);
        $approve([$base['node_id'] => ['a']])->assertUnprocessable()->assertJsonValidationErrors(["assignments.{$base['node_id']}.0"]);
        $approve(['abc' => [$this->userIdOf('u0004')]])->assertUnprocessable()->assertJsonValidationErrors(['assignments' => '指定するステップの処理対象者を指定してください']);

        $this->assertSame($base['revision'], $this->revisionOf($case));
    }

    public function test_a_base_date_that_is_not_a_string_is_422_on_the_application_and_the_draft_routes(): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();
        $before = WorkflowCase::query()->count();
        $plan = ['name' => '', 'comment' => null, 'values' => $this->planValues('封筒の確かめ')];

        $this->postJson('/workflow/flows/project_plan/apply', [...$plan, 'base_date' => ['2026-10-13']])->assertUnprocessable()->assertJsonValidationErrors(['base_date']);
        $this->postJson('/workflow/flows/project_plan/drafts', [...$plan, 'base_date' => ['x']])->assertUnprocessable()->assertJsonValidationErrors(['base_date']);
        $this->putJson("/workflow/drafts/{$draft}", ['revision' => $this->revisionOf($draft), 'name' => '', 'comment' => null, 'values' => ['title' => 'x'], 'base_date' => 1])->assertUnprocessable()->assertJsonValidationErrors(['base_date']);
        $this->postJson("/workflow/drafts/{$draft}/apply", ['revision' => $this->revisionOf($draft), 'name' => '', 'comment' => null, 'values' => $this->issueValues('x'), 'base_date' => ['x']])->assertUnprocessable()->assertJsonValidationErrors(['base_date']);
        $this->postJson('/workflow/flows/project_plan/apply', $plan)->assertUnprocessable()->assertJsonValidationErrors(['base_date' => '申請基準日を入力してください']);

        $this->assertSame($before, WorkflowCase::query()->count());
    }
}
