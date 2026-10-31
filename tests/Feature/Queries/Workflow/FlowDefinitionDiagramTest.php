<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\NodeSettingsInput;
use App\Actions\Workflow\SaveFlowReferrers;
use App\Actions\Workflow\SaveNodeSettings;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\Department;
use App\Queries\Workflow\FlowDefinitionDetail;
use App\Queries\Workflow\FlowDefinitionDiagram;
use App\Queries\Workflow\FlowDefinitionList;
use App\Queries\Workflow\FlowStepSettings;
use App\Queries\Workflow\StepTarget;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * 定義の図(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.2 節の図の形、図と意匠とE2E.md 8 節・9.3 節): 初期データの版 3(分岐 B =
 * 初期データ.md 3.2 節)の短い文・配置の仮の札・縦配置の完了の条件と人数・分岐の辺の名前。要約と短い文は同じ設定から同じ名前を使う。
 * 名前の無い対象者は「#{id}」。
 *
 * @phpstan-import-type DefinitionNode from FlowDefinitionDiagram
 * @phpstan-import-type DefinitionEdge from FlowDefinitionDiagram
 * @phpstan-import-type StepSettings from FlowStepSettings
 * @phpstan-import-type SubjectRow from StepTarget
 */
final class FlowDefinitionDiagramTest extends TransactionalTestCase
{
    use SendsFlowDefinitionRequests;

    public function test_the_definition_diagram_of_the_branch_route(): void
    {
        $diagram = $this->detail([])['diagram'];
        $node = fn (string $code) => collect($diagram['nodes'])->firstOrFail('code', $code);

        $this->assertSame(
            ['apply', 'section_chief', 'budget', 'manager', 'budget_end', 'council', 'owner'],
            array_column($diagram['nodes'], 'code'),
        );
        $this->assertSame([
            '一般利用者', '申請者の所属組織 + 課長', null, '申請者の上位組織 + 部長', null, '処理担当者は課長が指定', '課長が指定',
        ], array_column($diagram['nodes'], 'subtitle'));
        $this->assertSame([
            ['label' => '承認 1', 'optional' => false], ['label' => '承認 2', 'optional' => false],
            ['label' => '承認 3 から 5', 'optional' => true],
        ], $node('council')['placeholders']);
        $this->assertSame([], $node('owner')['placeholders']);
        $this->assertSame(['text' => '完了の条件 承認 2 人　人数 2〜5 人'], $node('council')['consensus']);
        $this->assertNull($node('manager')['consensus']);
        $caseOnly = fn (array $node) => [$node['parentId'], $node['sequence'], $node['state'], $node['people']];
        $this->assertSame(array_fill(0, 7, [null, null, null, []]), array_map($caseOnly, $diagram['nodes']));
        $this->assertSame(array_fill(0, 7, null), array_column($diagram['edges'], 'taken'));
        $this->assertSame(
            [null, null, '予算 300 万円以上', '何もしない', null, null, null],
            array_column($diagram['edges'], 'label'),
        );
    }

    /**
     * 要約と図の短い文は同じ名前(課長・部長・指定するステップ)を使う。
     */
    public function test_the_summary_and_the_subtitle_use_the_same_names(): void
    {
        $subtitles = collect($this->detail([])['diagram']['nodes'])->pluck('subtitle', 'code');
        $summary = fn (string $code) => $this->detail(['node' => $code])['selectedNode']['summary'];

        $this->assertSame(['申請者の上位組織 + 部長', '申請者の上位組織の部長が承認します。'], [$subtitles['manager'], $summary('manager')]);
        $this->assertSame(['課長が指定', '課長が選択した 1 人が承認します。'], [$subtitles['owner'], $summary('owner')]);
        $this->assertSame(['一般利用者', '一般利用者が申請できます。'], [$subtitles['apply'], $summary('apply')]);
    }

    /**
     * 名前の無い対象者(今日に有効な期間の行が無い退職者 u0014)は「#{id}」: 図の短い文・要約・参照者の行と一覧の参照者。
     */
    public function test_a_subject_without_a_name_is_shown_by_its_id(): void
    {
        $retired = $this->seededAccount('u0014')->id;
        $accounting = Department::query()->where('code', 'accounting')->sole()->id;
        $user = new SelectionCondition(SelectionConditionKind::User, $retired);
        $department = new SelectionCondition(SelectionConditionKind::Department, $accounting);
        app(SaveFlowReferrers::class)->execute(self::PLAN, [$user, $department]);
        $sendBacks = ['apply', 'section_chief'];
        $settings = new NodeSettingsInput(WorkflowTargetKind::Fixed, [$user], sendBackNodeCodes: $sendBacks);
        app(SaveNodeSettings::class)->execute(self::PLAN, 3, 'manager', $settings);

        $detail = $this->detail(['node' => 'manager']);
        $flows = app(FlowDefinitionList::class)->flows($this->today(), 'ja');

        $manager = collect($detail['diagram']['nodes'])->firstOrFail('code', 'manager');
        $texts = [$manager['subtitle'], $detail['selectedNode']['summary']];
        $this->assertSame(["#{$retired}", "#{$retired}が承認します。"], $texts);
        $this->assertSame(["#{$retired}", '経理部'], array_column($detail['referrers']['rows'], 'name'));
        $this->assertSame("#{$retired}、経理部", $flows[0]['referrerSummary']);
    }

    /**
     * 詳細の遅延の props のうち、このテストが見る 3 つを評価した値。
     *
     * @param  array<string, string>  $query
     * @return array{diagram: array{nodes: list<DefinitionNode>, edges: list<DefinitionEdge>},
     *     selectedNode: StepSettings, referrers: array{rows: list<SubjectRow>}}
     */
    private function detail(array $query): array
    {
        $props = app(FlowDefinitionDetail::class)
            ->show(self::PLAN, null, null, $query['node'] ?? null, $this->today(), 'ja');

        return [
            'diagram' => $props['diagram'](),
            'selectedNode' => $props['selectedNode'](),
            'referrers' => $props['referrers'](),
        ];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today(AccessContext::TIMEZONE);
    }
}
