<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 業務の一覧と詳細(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/業務の画面.md 2.1・2.3、認可とメニュー/README.md 2 節、メニューと初期データ.md
 * 8 節のこの区切りのルートの分):ページ Records/Index・Records/Show の props。ルートの認可(全員)の後に、業務モジュールの read を判定する(無い・明細のモジュールは 404、
 * read が無ければレコードの有無に依らず 403、別の業務モジュールのレコードは 404)。can は判定と同じ。
 * 初期データを入れ、今日を 2026-10-11 に固定し、ルートの認可を外さずに開く。
 */
final class RecordControllerTest extends TransactionalTestCase
{
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_a_guest_opens_the_list_with_the_definition_the_listed_values_and_can(): void
    {
        $this->get('/records/data/project')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Index')
            ->where('module.code', 'project')
            ->where('module.name', 'プロジェクト')
            ->has('module.fields', 9)
            // 業務データの入れ物の初期データ 3 件と、ワークフローの初期データの申請が作った 11 件(2 段目の見本 5・版 3 の見本 4・案件の運用の見本 2 =
            // WF-000013・14。id の降順。経費精算の電子化 = 入れ物の 3 件目)
            ->where('records.total', 14)
            ->where('records.currentPage', 1)
            ->where('records.data.11.values.name', '経費精算の電子化')
            ->where('records.data.11.values.department.label', '経理部')
            ->where('records.data.11.values.status', ['code' => 'done', 'name' => '完了'])
            ->where('query', ['filters' => [], 'sort' => '-id'])
            ->where('can', ['create' => true])
        );
    }

    public function test_the_list_filters_and_sorts_by_the_query_and_a_partial_reload_does_not_rebuild_the_definition(): void
    {
        $response = $this->get('/records/data/project?filter[status]=done&sort=start_date');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)
            ->where('records.data.0.values.name', '経費精算の電子化')
            ->where('query', ['filters' => ['status' => 'done'], 'sort' => 'start_date'])
            ->reloadOnly(['records', 'query'], fn (Assert $reload) => $reload->missing('module')->missing('can')->has('records'))
        );
    }

    public function test_a_guest_opens_the_detail_with_the_details_and_the_authors(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');

        $this->get("/records/data/project/{$project->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Show')
            ->where('record.id', $project->id)
            ->where('record.values.department.label', '開発部')
            ->where('record.values.manager.label', '佐藤 拓也')
            ->where('record.values.budget', 1200)
            ->has('record.details.milestones', 4)
            ->where('record.details.milestones.1.values.effort', '15.5')
            ->where('record.details.milestones.1.values.is_done', true)
            ->where('record.createdBy.label', '伊藤 涼子')
            ->where('can', ['update' => true, 'delete' => true, 'copy' => true, 'attach' => true, 'apply' => false])
        );
    }

    public function test_an_authenticated_user_opens_the_list_the_detail_and_the_candidates(): void
    {
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $this->actingAs(User::query()->where('code', 'u0001')->sole());

        $this->get('/records/data/project_issue')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can', ['create' => true]));
        $this->get("/records/data/project_issue/{$issue->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('record.values.project.label', '社内ポータル刷新')
            ->where('can', ['update' => true, 'delete' => true, 'copy' => true, 'attach' => true, 'apply' => true])
        );
        $this->getJson('/records/data/project/candidates?q=社内')->assertOk();
    }

    public function test_without_read_the_list_the_detail_and_the_candidates_are_forbidden_whether_or_not_the_record_exists(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $this->deny('project', RecordModuleAction::Read);

        $this->get('/records/data/project')->assertForbidden();
        $this->get("/records/data/project/{$project->id}")->assertForbidden();
        $this->get('/records/data/project/999999')->assertForbidden();
        $this->getJson('/records/data/project/candidates?q=社内')->assertForbidden();
    }

    public function test_hides_the_title_of_a_referenced_record_without_read_on_its_module(): void
    {
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $this->deny('project', RecordModuleAction::Read);

        $this->get("/records/data/project_issue/{$issue->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('record.values.project.label', null)
        );
    }

    public function test_can_follows_the_judgement_of_each_action(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $this->deny('project', RecordModuleAction::Create);
        $this->deny('project', RecordModuleAction::Delete);

        $this->get('/records/data/project')->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false]));
        $this->get("/records/data/project/{$project->id}")->assertInertia(fn (Assert $page) => $page->where('can', ['update' => true, 'delete' => false, 'copy' => false, 'attach' => true, 'apply' => false]));
    }

    #[TestWith(['/records/data/nothing'])]
    #[TestWith(['/records/data/project_milestone'])]
    #[TestWith(['/records/data/project_milestone/1'])]
    #[TestWith(['/records/data/Project'])]
    #[TestWith(['/records/data/project/999999'])]
    #[TestWith(['/records/data/project/1234567890123456789'])]
    public function test_a_missing_module_or_record_a_details_module_and_an_id_out_of_the_route_constraint_are_not_found(string $url): void
    {
        $this->get($url)->assertNotFound();
    }

    public function test_a_record_of_another_module_is_not_found(): void
    {
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $milestone = Record::query()->whereNotNull('parent_record_id')->firstOrFail();

        $this->get("/records/data/project/{$issue->id}")->assertNotFound();
        $this->get("/records/data/project/{$milestone->id}")->assertNotFound();
    }
}
