<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\CreateWorkflowFlow;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\UpdateDraft;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 添付の可否の写し(docs の 設計/ワークフロー/申請の周辺/データモデル.md の 2.1 節・2.2 節・3.1 節・3.4 節): 申請(新規・一時保存から)は
 * 申請の時のフローの「添付を使う」を案件へ写し、一時保存は NULL のまま、再申請は変えない。ステップの「添付を追加できない」は
 * 版の複製と申請の写しがほかの列と同じに運び、展開の行は親の値を写す。
 */
final class AttachmentSettingCopyTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    /**
     * @return array<string, array{bool}>
     */
    public static function flowSettings(): array
    {
        return ['使う' => [true], '使わない' => [false]];
    }

    #[DataProvider('flowSettings')]
    public function test_a_new_application_copies_the_setting_of_the_flow(bool $enabled): void
    {
        $this->createFlow();
        $this->flow()->update(['is_attachment_enabled' => $enabled]);

        $case = $this->applyAs($this->seededUser('u0001'));

        $this->assertSame($enabled, $case->refresh()->is_attachment_enabled);
    }

    public function test_a_draft_keeps_no_copy_and_the_application_from_it_copies_the_setting_at_that_time(): void
    {
        $this->createFlow();
        $context = $this->contextOf($this->seededUser('u0001'));
        $caseId = app(CreateDraft::class)->execute($context, self::FLOW_CODE, $this->draftInput());
        $this->flow()->update(['is_attachment_enabled' => false]);
        app(UpdateDraft::class)->execute($context, $caseId, 0, $this->draftInput());
        $this->assertNull(WorkflowCase::query()->findOrFail($caseId)->is_attachment_enabled);

        app(ApplyCase::class)->executeFromDraft(
            $context, $caseId, 1, $this->projectInput(), $this->operationDay()->toDateString(),
        );

        $this->assertFalse(WorkflowCase::query()->findOrFail($caseId)->is_attachment_enabled);
    }

    public function test_reapplication_keeps_the_copy_made_at_the_application(): void
    {
        $this->createFlow();
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001);
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');
        $this->flow()->update(['is_attachment_enabled' => false]);

        $this->reapply($u0001, $case);

        $this->assertTrue($case->refresh()->is_attachment_enabled);
    }

    public function test_the_application_copies_the_step_setting_and_the_children_copy_their_parent(): void
    {
        $versionId = $this->addVersion($this->attachmentRoute());
        [$case] = $this->applyOnRoute(['budget' => 100]);

        $this->approve($case, 'chief', 'u0002', ['line' => ['u0004'], 'council' => ['u0005', 'u0009']]);
        $this->approve($case, 'line.1', 'u0004');

        $expected = ['apply' => false, 'chief' => true, 'line' => true, 'council' => false, 'final' => false];
        $this->assertSame($expected, $this->routeSettings($versionId));
        $copied = [...$expected, 'line.1' => true, 'council.1' => false, 'council.2' => false];
        $actual = array_intersect_key($this->caseSettings($case), $copied);
        ksort($copied);
        ksort($actual);
        $this->assertSame($copied, $actual);
    }

    public function test_the_version_copy_keeps_the_step_setting(): void
    {
        $this->createFlow();
        $sourceVersionId = $this->firstVersionId(self::FLOW_CODE);
        WorkflowRouteNode::query()->where('workflow_flow_version_id', $sourceVersionId)->where('code', 'approval1')
            ->update(['prevents_attachment_add' => true]);

        app(CreateWorkflowFlow::class)->execute(
            'wf_plan_copy', '複製', null, self::CONTENT_CODE, self::FLOW_CODE,
            CarbonImmutable::parse(self::FLOW_START_DATE), null, [],
        );

        $expected = ['apply' => false, 'approval1' => true, 'approval2' => false];
        $this->assertSame($expected, $this->routeSettings($sourceVersionId));
        $this->assertSame($expected, $this->routeSettings($this->firstVersionId('wf_plan_copy')));
    }

    /**
     * 申請 → chief(u0002。追加できない。指定する)→ line(横配置。追加できない)→ council(縦配置。人数 2 で可決)→ final(u0010)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function attachmentRoute(): array
    {
        $front = ['apply', 'chief'];

        return [[
            $this->applyNode(),
            new RouteNodeInput(
                'chief', 'chief', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [$this->userCondition('u0002')],
                ['ja' => 'chief'], sendBackCodes: ['apply'], preventsAttachmentAdd: true,
            ),
            new RouteNodeInput(
                'line', '順番の承認', WorkflowNodeKind::Serial, WorkflowTargetKind::Designated, designatorCode: 'chief',
                minAssignees: 1, maxAssignees: 3, sendBackCodes: $front, preventsAttachmentAdd: true,
            ),
            new RouteNodeInput(
                'council', '合議', WorkflowNodeKind::Parallel, WorkflowTargetKind::Designated, designatorCode: 'chief',
                minAssignees: 2, maxAssignees: 3, decisionRule: WorkflowDecisionRule::Count, decisionValue: 2,
                sendBackCodes: $front,
            ),
            $this->approveNode('final', 'u0010', $front),
        ], $this->chain(['apply', 'chief'], ['chief', 'line'], ['line', 'council'], ['council', 'final'])];
    }

    private function draftInput(): DraftInput
    {
        return new DraftInput('案件名', null, ['name' => '下書き'], []);
    }

    private function flow(): WorkflowFlow
    {
        return WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole();
    }

    /**
     * @return array<string, bool> ノードのコード → 添付を追加できない
     */
    private function routeSettings(int $versionId): array
    {
        return WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->orderBy('position')->get()
            ->mapWithKeys(fn (WorkflowRouteNode $node) => [$node->code => $node->prevents_attachment_add])
            ->all();
    }

    /**
     * @return array<string, bool> ノードの名札 → 添付を追加できない
     */
    private function caseSettings(WorkflowCase $case): array
    {
        $settings = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)
            ->pluck('prevents_attachment_add', 'id')->all();

        return array_map(fn (int $id) => $settings[$id], $this->labelIds($case));
    }

    private function firstVersionId(string $flowCode): int
    {
        $flowId = WorkflowFlow::query()->where('code', $flowCode)->sole()->id;

        return WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->where('number', 1)->sole()->id;
    }
}
