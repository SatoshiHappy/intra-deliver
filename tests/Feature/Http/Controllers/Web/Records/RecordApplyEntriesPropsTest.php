<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowFlow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AppliesFromRecords;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの詳細の申請の入口(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 2 節、
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 6.1 節): can.apply = 入口が空でない、applyEntries = 入口の一覧
 * (名前・説明・申請の画面の URL。can.apply が偽なら空)。持ち主の有無は見ない(hold で押せない形にする)。問い合わせの数は入口の数に比例しない。
 * 業務モジュール project の入口 = 初期データのフローとテストのフロー、project_issue の入口 = 初期データのフロー 1 つ。
 */
final class RecordApplyEntriesPropsTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->createFlow();
    }

    public function test_the_detail_lists_one_or_more_entries_with_the_url_of_the_form(): void
    {
        $u0001 = $this->seededUser('u0001');
        $project = $this->projectRecord($u0001);
        $issue = Record::factory()->create(['record_module_id' => $this->moduleIdOf('project_issue')]);
        $this->actingAs($u0001);

        $this->get("/records/data/project/{$project->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.apply', true)
            ->where('applyEntries', $this->entriesOf('project', $project->id))
            ->has('applyEntries', 2));
        $this->get("/records/data/project_issue/{$issue->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.apply', true)
            ->where('applyEntries', $this->entriesOf('project_issue', $issue->id))
            ->has('applyEntries', 1));
    }

    /**
     * 持ち主(申請中)があっても入口は出す(押せない形は hold で決める)。
     */
    public function test_a_held_record_keeps_the_entries(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->applyFromRecord($u0001, $record->id);
        $this->actingAs($u0001);

        $this->get("/records/data/project/{$record->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.apply', true)
            ->has('applyEntries', 2)
            ->whereNot('hold', null));
    }

    /**
     * 読み取りだけの人(update が無い)には入口が無い。
     */
    public function test_a_reader_without_update_has_no_entry(): void
    {
        [$u0001, $u0003] = [$this->seededUser('u0001'), $this->seededUser('u0003')];
        $record = $this->projectRecord($u0001);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0003);
        $this->actingAs($u0001);

        $this->get("/records/data/project/{$record->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.apply', false)
            ->where('can.update', false)
            ->where('applyEntries', []));
    }

    /**
     * 入口(フロー)と、自分ができないフロー(代理元を引く)が増えても、詳細の 1 描画の問い合わせの数は変わらない。
     */
    public function test_the_number_of_queries_does_not_grow_with_the_entries(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $onlyU0003 = [$this->userCondition('u0003')];
        $this->createFlow('wf_plan_x', 'u0003 だけの計画', applicants: $onlyU0003);
        $this->actingAs($u0001);
        $url = "/records/data/project/{$record->id}";
        $this->get($url)->assertOk();

        $before = count($this->recordedSql(fn () => $this->get($url)->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('applyEntries', 2),
        )));
        $this->createFlow('wf_plan_b', 'プロジェクト計画の承認 B');
        $this->createFlow('wf_plan_c', 'プロジェクト計画の承認 C');
        $this->createFlow('wf_plan_y', 'u0003 だけの計画 2', applicants: $onlyU0003);
        $after = count($this->recordedSql(fn () => $this->get($url)->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('applyEntries', 4),
        )));

        $this->assertSame($before, $after);
    }

    /**
     * 期待する入口(フローの一覧の並び = sort_key・code)。
     *
     * @return list<array{name: string, description: string|null, url: string}>
     */
    private function entriesOf(string $moduleCode, int $recordId): array
    {
        $flows = WorkflowFlow::query()
            ->whereHas('workflowContent.recordModule', fn (Builder $module) => $module->where('code', $moduleCode))
            ->orderBy('sort_key')->orderBy('code')->get();

        return array_values($flows->map(fn (WorkflowFlow $flow) => [
            'name' => $flow->name,
            'description' => $flow->description,
            'url' => route('workflow.applications.create', ['flow' => $flow->code, 'record' => $recordId]),
        ])->all());
    }
}
