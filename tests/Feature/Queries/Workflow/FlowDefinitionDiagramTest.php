<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\NodeSettingsInput;
use App\Actions\Workflow\SaveFlowReferrers;
use App\Actions\Workflow\SaveNodeSettings;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\Department;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\SelectionSubjects;
use App\Queries\Master\Api\MasterNames;
use App\Queries\Workflow\FlowDefinitionDetail;
use App\Queries\Workflow\FlowDefinitionDiagram;
use App\Queries\Workflow\FlowDefinitionList;
use App\Queries\Workflow\FlowStepSettings;
use App\Queries\Workflow\StepTarget;
use App\Queries\Workflow\SubjectRows;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Mockery;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * 定義の図(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.2 節の図の形、図と意匠とE2E.md 8 節・9.3 節): 初期データの版 3(分岐 B =
 * 初期データ.md 3.2 節)の短い文・配置の仮の札・縦配置の完了の条件と人数・分岐の辺の名前と条件の文。要約と短い文は同じ設定から同じ名前を使う。
 * 名前の無い対象者は「#{id}」。短い文の期限と確認の札は 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.6 節(版 3 = 課長・部長・合議・
 * 責任者に期限、課長・部長に確認する人 山田 恵 = 案件の運用/初期データ.md 3 節)。
 *
 * @phpstan-import-type DefinitionNode from FlowDefinitionDiagram
 * @phpstan-import-type DefinitionEdge from FlowDefinitionDiagram
 * @phpstan-import-type StepSettings from FlowStepSettings
 * @phpstan-import-type Detail from FlowDefinitionDetail
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
        $em = fn (string $text) => ['text' => $text, 'emphasis' => true];
        $this->assertSame([
            ['一般利用者'], [$em('申請者'), 'の所属組織の', $em('課長'), '　期限 3 日'], null,
            [$em('申請者'), 'の上位組織の', $em('部長'), '　期限 5 日'], null, ['課長が承認者を選択　期限 3 日'],
            ['課長が承認者を選択　期限 3 日'],
        ], array_column($diagram['nodes'], 'subtitle'));
        $this->assertSame(
            [null, '確認：山田 恵', null, '確認：山田 恵', null, null, null],
            array_column($diagram['nodes'], 'confirmBadge'),
        );
        $this->assertSame([
            ['label' => '承認 1', 'optional' => false], ['label' => '承認 2', 'optional' => false],
            ['label' => '承認 3〜5', 'optional' => true],
        ], $node('council')['placeholders']);
        $this->assertSame([], $node('owner')['placeholders']);
        $this->assertSame(['text' => '完了の条件 承認 2 人　人数 2〜5 人'], $node('council')['consensus']);
        $this->assertNull($node('manager')['consensus']);
        $caseOnly = fn (array $node) => [$node['parentId'], $node['sequence'], $node['state'], $node['people']];
        $this->assertSame(array_fill(0, 7, [null, null, null, []]), array_map($caseOnly, $diagram['nodes']));
        $this->assertSame(array_fill(0, 7, null), array_column($diagram['edges'], 'taken'));
        $this->assertSame(
            [null, null, '予算 300 万円以上', '予算 300 万円未満', null, null, null],
            array_column($diagram['edges'], 'label'),
        );
        // 条件の文は、コンテンツの業務モジュールの今の項目の名前で作る(名前を持つ辺だけ)
        $this->assertSame(
            [null, null, '予算(万円) ≧ 300', '予算(万円) < 300', null, null, null],
            array_column($diagram['edges'], 'conditionText'),
        );
    }

    /**
     * 要約と図の短い文は同じ名前(課長・部長・指定するステップ)を使う。要約には処理期限と確認する人の文が続く
     * (案件の運用/画面/管理と通知の画面.md の 7.6 節。初期データの版 3 = 部長は期限 5 日・山田 恵、責任者は期限 3 日)。
     */
    public function test_the_summary_and_the_subtitle_use_the_same_names(): void
    {
        $subtitles = collect($this->detail([])['diagram']['nodes'])->pluck('subtitle', 'code');
        $summary = fn (string $code) => $this->detail(['node' => $code])['selectedNode']['summary'];

        $manager = ['申請者の上位組織の部長が承認します。到達した日から 5 日以内に処理します。山田 恵が確認します。添付ファイルを追加できません。'];
        $owner = ['課長が選択した 1 人が承認します。到達した日から 3 日以内に処理します。確認する人はいません。'];
        $em = fn (string $text) => ['text' => $text, 'emphasis' => true];
        $managerSubtitle = [$em('申請者'), 'の上位組織の', $em('部長'), '　期限 5 日'];
        $this->assertSame([$managerSubtitle, $manager], [$subtitles['manager'], $summary('manager')]);
        $this->assertSame([['課長が承認者を選択　期限 3 日'], $owner], [$subtitles['owner'], $summary('owner')]);
        $apply = ['一般利用者が申請できます。確認する人はいません。'];
        $this->assertSame([['一般利用者'], $apply], [$subtitles['apply'], $summary('apply')]);
    }

    /**
     * 名前の無い対象者(今日に有効な期間の行が無い退職者 u0014)は「#{id}」: 図の短い文・要約・参照者の行と一覧の参照者・確認の札。
     * 確認の札は確認する人の対象者の条件の並び(入力の並び)の 1 人目と、残りの人数。
     */
    public function test_a_subject_without_a_name_is_shown_by_its_id(): void
    {
        $retired = $this->seededAccount('u0014')->id;
        $accounting = Department::query()->where('code', 'accounting')->sole()->id;
        $user = new SelectionCondition(SelectionConditionKind::User, $retired);
        $department = new SelectionCondition(SelectionConditionKind::Department, $accounting);
        app(SaveFlowReferrers::class)->execute(self::PLAN, [$user, $department]);
        $sendBacks = ['apply', 'section_chief'];
        $confirmers = [$retired, $this->seededAccount('u0010')->id];
        $settings = new NodeSettingsInput(
            WorkflowTargetKind::Fixed, [$user], sendBackNodeCodes: $sendBacks, confirmers: $confirmers,
        );
        app(SaveNodeSettings::class)->execute(self::PLAN, 3, 'manager', $settings);

        $detail = $this->detail(['node' => 'manager']);
        $flows = app(FlowDefinitionList::class)->flows($this->today(), 'ja');

        $manager = collect($detail['diagram']['nodes'])->firstOrFail('code', 'manager');
        $texts = [$manager['subtitle'], $detail['selectedNode']['summary'], $manager['confirmBadge']];
        $summary = ["#{$retired}が承認します。期限はありません。#{$retired}、山田 恵が確認します。"];
        $this->assertSame([["#{$retired}"], $summary, "確認：#{$retired} ほか 1 人"], $texts);
        $this->assertSame(["#{$retired}", '経理部'], array_column($detail['referrers']['rows'], 'name'));
        // 今日の主所属が無いユーザーと組織の行は、所属を持たない
        $this->assertSame([null, null], array_column($detail['referrers']['rows'], 'affiliation'));
        $this->assertSame("#{$retired}、経理部", $flows[0]['referrerSummary']);
    }

    /**
     * 確認の札の名前は所属を使わないので、共通マスタの主所属を引かない(所属は SubjectRows::of の札の行だけ)。
     */
    public function test_the_names_of_the_confirm_badges_do_not_read_the_affiliations(): void
    {
        $groupId = (int) WorkflowRouteNode::query()->whereNotNull('confirm_authz_subject_group_id')
            ->orderBy('id')->value('confirm_authz_subject_group_id');
        $this->mock(
            MasterNames::class,
            fn (LegacyMockInterface $mock) => $mock->shouldNotReceive('mainDepartmentNames'),
        );

        $names = app(SubjectRows::class)->namesOf([$groupId, null, $groupId], $this->today(), 'ja');

        $this->assertSame([$groupId => ['山田 恵']], $names);
    }

    /**
     * 全体の読み込み(図と選んだステップの欄)は、対象者の行を多くて 2 回で引く(処理対象者を図で 1 回、選んだステップの確認する人を 1 回)。
     */
    public function test_the_whole_page_reads_the_subject_rows_at_most_twice(): void
    {
        $rows = $this->spiedSubjectRows();
        $rows->shouldReceive('of')->twice()->passthru();
        $rows->shouldReceive('namesOf')->once()->passthru();

        $props = $this->showNode('manager');
        $diagram = $props['diagram']();
        $manager = $props['selectedNode']();

        $badge = collect($diagram['nodes'])->firstOrFail('code', 'manager')['confirmBadge'];
        $this->assertSame(['確認：山田 恵', ['山田 恵']], [$badge, array_column($manager['confirmers'] ?? [], 'name')]);
    }

    /**
     * ステップの選び直し(選んだステップの欄だけの部分の再読み込み)は、選んだステップの処理対象者と確認する人を 1 回で引く。
     */
    public function test_reselecting_a_step_reads_only_its_subject_rows_at_once(): void
    {
        $yamada = $this->seededAccount('u0010')->id;
        $settings = new NodeSettingsInput(
            WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::User, $yamada)],
            sendBackNodeCodes: ['apply', 'section_chief'], confirmers: [$yamada],
        );
        app(SaveNodeSettings::class)->execute(self::PLAN, 3, 'manager', $settings);
        $rows = $this->spiedSubjectRows();
        // 処理対象者と確認する人の 2 つの対象者
        $rows->shouldReceive('of')->once()->withArgs(fn (array $ids) => count($ids) === 2)->passthru();

        $manager = $this->showNode('manager')['selectedNode']();

        $this->assertSame(['山田 恵'], array_column($manager['confirmers'] ?? [], 'name'));
        $this->assertSame(['山田 恵'], array_column($manager['target']['rows'] ?? [], 'name'));
    }

    /**
     * 定義の図の確認の札は、所属を引く SubjectRows::of でなく名前だけの namesOf で引く(of に確認する人の対象者を渡さない)。
     */
    public function test_the_confirm_badges_of_the_definition_diagram_do_not_read_the_affiliations(): void
    {
        $confirmIds = WorkflowRouteNode::query()->whereNotNull('confirm_authz_subject_group_id')
            ->pluck('confirm_authz_subject_group_id')->map(fn (mixed $id) => (int) $id)->all();
        $rows = $this->spiedSubjectRows();
        $rows->shouldReceive('of')->once()
            ->withArgs(fn (array $ids) => array_intersect($ids, $confirmIds) === [])->passthru();
        $rows->shouldReceive('namesOf')->once()->passthru();

        $diagram = $this->showNode('manager')['diagram']();

        $this->assertSame('確認：山田 恵', collect($diagram['nodes'])->firstOrFail('code', 'manager')['confirmBadge']);
    }

    /**
     * 呼んだ回数と引数を確かめる SubjectRows(確かめないメソッドは元の処理)。
     */
    private function spiedSubjectRows(): MockInterface
    {
        $rows = Mockery::mock(SubjectRows::class, [app(SelectionSubjects::class), app(MasterNames::class)])
            ->makePartial();
        $this->app->instance(SubjectRows::class, $rows);

        return $rows;
    }

    /**
     * @return Detail
     */
    private function showNode(string $code): array
    {
        return app(FlowDefinitionDetail::class)->show(self::PLAN, null, null, $code, $this->today(), 'ja');
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
