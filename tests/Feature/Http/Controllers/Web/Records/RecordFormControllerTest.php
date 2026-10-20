<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 業務の登録・更新・削除(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/業務の画面.md 2.2・2.4、認可とメニュー/README.md 2 節、
 * メニューと初期データ.md 8 節の残り(管理を除く)):ページ Records/Create・Records/Edit と、records.data.store・update・destroy。登録の画面と登録は create、
 * 編集の画面は read と update、更新は update、削除は delete を判定する(判定の順 = 認可とメニュー/README.md 2.2)。成功は 303 のリダイレクトと成功のトースト、
 * 検証の失敗は元の画面へ戻り項目のキーにエラーが付く。初期データを入れ、今日を 2026-10-11 に固定し、ルートの認可を外さずに開く。
 */
final class RecordFormControllerTest extends TransactionalTestCase
{
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_everyone_opens_the_forms_and_creates_updates_and_deletes_with_the_initial_policies(?string $userCode): void
    {
        if ($userCode !== null) {
            $this->actingAs(User::query()->where('code', $userCode)->sole());
        }

        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');

        $this->get('/records/data/project_issue/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Create')
            ->where('module.code', 'project_issue')
            ->where('module.rules.0.kind', 'required_when')
            ->where('module.rules.0.condition', ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done']]])
            ->where('can', ['read' => true])
        );

        $stored = $this->post('/records/data/project_issue', ['values' => ['title' => '新しい課題', 'project' => $project->id]]);
        $created = $this->latestRecordOf('project_issue');
        $stored->assertStatus(303)->assertRedirect("/records/data/project_issue/{$created->id}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '登録しました']);

        $this->get("/records/data/project_issue/{$created->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Edit')
            ->where('module.code', 'project_issue')
            ->where('record.id', $created->id)
            ->where('record.values.title', '新しい課題')
            ->where('record.values.project.label', '社内ポータル刷新')
            ->where('record.values.priority', ['code' => 'medium', 'name' => '中'])
            ->where('record.details', [])
            ->where('record.version', $created->refresh()->version())
            ->missing('record.createdAt')
        );

        $this->put("/records/data/project_issue/{$created->id}", ['values' => ['title' => '変えた課題', 'project' => $project->id], 'version' => $created->version()])
            ->assertStatus(303)->assertRedirect("/records/data/project_issue/{$created->id}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '更新しました']);
        $this->get("/records/data/project_issue/{$created->id}")->assertInertia(fn (Assert $page) => $page->where('record.values.title', '変えた課題'));

        $this->delete("/records/data/project_issue/{$created->id}")
            ->assertStatus(303)->assertRedirect('/records/data/project_issue')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '削除しました']);
        $this->assertModelMissing($created);
    }

    public function test_a_validation_failure_returns_to_the_form_with_the_key_of_the_rule_and_writes_nothing(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $count = Record::query()->count();

        $this->from('/records/data/project_issue/create')
            ->post('/records/data/project_issue', ['values' => ['title' => '完了した課題', 'project' => $project->id, 'status' => 'done', 'resolution' => '  ']])
            ->assertRedirect('/records/data/project_issue/create')
            ->assertSessionHasErrors(['values.resolution' => '状態が完了のときは解決内容を入力してください']);

        $this->assertSame($count, Record::query()->count());
    }

    public function test_the_update_returns_the_failure_of_the_compare_rule_to_the_edit_form(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $before = $project->field_values;

        $this->from("/records/data/project/{$project->id}/edit")
            ->put("/records/data/project/{$project->id}", ['values' => ['end_date' => '2026-06-30'], 'version' => $project->version()])
            ->assertRedirect("/records/data/project/{$project->id}/edit")
            ->assertSessionHasErrors(['values.end_date' => '終了日は開始日以後の日にしてください']);

        $this->assertEquals($before, $project->refresh()->field_values);
    }

    public function test_the_update_keeps_changes_adds_and_orders_the_detail_rows_of_the_input(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $rows = Record::query()->where('parent_record_id', $project->id)->orderBy('sort_key')->orderBy('id')->pluck('id')->all();
        $details = array_map(fn (int $id) => ['id' => $id], array_reverse($rows));

        $this->put("/records/data/project/{$project->id}", ['details' => ['milestones' => [...$details, ['values' => ['name' => 'リリース', 'due_date' => '2026-12-24']]]], 'version' => $project->version()])
            ->assertStatus(303);

        $this->get("/records/data/project/{$project->id}")->assertInertia(fn (Assert $page) => $page
            ->has('record.details.milestones', 5)
            ->where('record.details.milestones.0.values.name', '受け入れ')
            ->where('record.details.milestones.3.values.name', '要件定義')
            ->where('record.details.milestones.4.values.name', 'リリース')
            ->where('record.details.milestones.4.values.is_done', false)
        );
    }

    /**
     * 版を求めるのは更新の入口だけ(登録は版を送らずに通る = 上の登録)。版の無い更新は 422(version)で何も書かない(項目と規則/規則と業務処理/README.md 9.1)。
     */
    public function test_the_update_without_the_version_is_a_validation_error_and_writes_nothing(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $before = $project->field_values;

        $this->from("/records/data/project/{$project->id}/edit")
            ->put("/records/data/project/{$project->id}", ['values' => ['name' => '変えた名前']])
            ->assertRedirect("/records/data/project/{$project->id}/edit")
            ->assertSessionHasErrors(['version' => '版の指定がありません']);

        $this->assertEquals($before, $project->refresh()->field_values);
    }

    /**
     * 開いた後に別の保存が入ったら、開いたときの版の更新は編集の画面に戻り、キー record にエラーが付く(項目と規則/規則と業務処理/README.md 9.2 の 2、失敗と定義の変更.md 10 節)。
     */
    public function test_the_update_with_the_version_before_another_save_returns_to_the_edit_form_with_the_key_record(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $opened = $project->version();
        $this->travel(1)->seconds();
        $this->put("/records/data/project/{$project->id}", ['values' => ['name' => '先の保存'], 'version' => $opened])->assertStatus(303)->assertSessionHasNoErrors();

        $this->from("/records/data/project/{$project->id}/edit")
            ->put("/records/data/project/{$project->id}", ['values' => ['name' => '後の保存'], 'version' => $opened])
            ->assertRedirect("/records/data/project/{$project->id}/edit")
            ->assertSessionHasErrors(['record' => 'このレコードは、開いた後に更新されています。画面を開き直してから、もう一度保存してください。']);

        $this->assertSame($project->id, $this->recordNamed('project', 'name', '先の保存')->id);
    }

    public function test_deleting_a_referenced_record_returns_to_the_detail_with_the_key_record(): void
    {
        $project = $this->recordNamed('project', 'name', '新規顧客開拓キャンペーン');

        $this->from("/records/data/project/{$project->id}")
            ->delete("/records/data/project/{$project->id}")
            ->assertRedirect("/records/data/project/{$project->id}")
            ->assertSessionHasErrors(['record' => 'このレコードはほかのデータから参照されているため削除できません']);

        $this->assertModelExists($project);
    }

    public function test_without_read_on_the_referenced_module_a_new_reference_is_rejected_and_an_unchanged_one_passes(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $this->deny('project', RecordModuleAction::Read);
        $count = Record::query()->count();

        $this->from('/records/data/project_issue/create')
            ->post('/records/data/project_issue', ['values' => ['title' => '新しい課題', 'project' => $project->id]])
            ->assertSessionHasErrors('values.project');
        $this->assertSame($count, Record::query()->count());

        $this->put("/records/data/project_issue/{$issue->id}", ['values' => ['title' => '件名だけ変える', 'project' => $project->id], 'version' => $issue->version()])
            ->assertStatus(303)->assertSessionHasNoErrors();
    }

    public function test_without_read_the_edit_form_is_forbidden_whether_or_not_the_record_exists_but_the_create_form_opens(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $this->deny('project', RecordModuleAction::Read);

        $this->get("/records/data/project/{$project->id}/edit")->assertForbidden();
        $this->get('/records/data/project/999999/edit')->assertForbidden();
        $this->get('/records/data/project/create')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can', ['read' => false]));
    }

    public function test_without_create_the_create_form_and_the_store_are_forbidden_and_nothing_is_written(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $this->deny('project_issue', RecordModuleAction::Create);
        $count = Record::query()->count();

        $this->get('/records/data/project_issue/create')->assertForbidden();
        $this->post('/records/data/project_issue', ['values' => ['title' => '新しい課題', 'project' => $project->id]])->assertForbidden();

        $this->assertSame($count, Record::query()->count());
    }

    public function test_without_update_the_edit_form_and_the_update_are_forbidden_whether_or_not_the_record_exists_and_nothing_is_written(): void
    {
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $before = $issue->field_values;
        $this->deny('project_issue', RecordModuleAction::Update);

        $this->get("/records/data/project_issue/{$issue->id}/edit")->assertForbidden();
        $this->put("/records/data/project_issue/{$issue->id}", ['values' => ['title' => '変えた課題']])->assertForbidden();
        $this->put('/records/data/project_issue/999999', ['values' => ['title' => '変えた課題']])->assertForbidden();

        $this->assertEquals($before, $issue->refresh()->field_values);
    }

    public function test_without_delete_the_destroy_is_forbidden_whether_or_not_the_record_exists_and_nothing_is_deleted(): void
    {
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $this->deny('project_issue', RecordModuleAction::Delete);

        $this->delete("/records/data/project_issue/{$issue->id}")->assertForbidden();
        $this->delete('/records/data/project_issue/999999')->assertForbidden();

        $this->assertModelExists($issue);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[TestWith(['GET', '/records/data/nothing/create'])]
    #[TestWith(['POST', '/records/data/nothing'])]
    #[TestWith(['GET', '/records/data/nothing/1/edit'])]
    #[TestWith(['PUT', '/records/data/nothing/1'])]
    #[TestWith(['DELETE', '/records/data/nothing/1'])]
    #[TestWith(['GET', '/records/data/project_milestone/create'])]
    #[TestWith(['POST', '/records/data/project_milestone'])]
    #[TestWith(['GET', '/records/data/project_milestone/1/edit'])]
    #[TestWith(['PUT', '/records/data/project_milestone/1'])]
    #[TestWith(['DELETE', '/records/data/project_milestone/1'])]
    #[TestWith(['POST', '/records/data/nothing', ['values' => 'x']])]
    #[TestWith(['PUT', '/records/data/project_issue/999999', ['values' => 'x']])]
    public function test_a_missing_module_record_or_a_details_module_is_not_found_before_checking_the_envelope(string $method, string $url, array $input = ['values' => []]): void
    {
        $count = Record::query()->count();

        $this->call($method, $url, $input)->assertNotFound();

        $this->assertSame($count, Record::query()->count());
    }

    public function test_a_record_of_another_module_is_not_found_and_nothing_changes(): void
    {
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $milestone = Record::query()->whereNotNull('parent_record_id')->firstOrFail();
        $before = $issue->field_values;

        foreach ([$issue, $milestone] as $record) {
            $this->get("/records/data/project/{$record->id}/edit")->assertNotFound();
            $this->put("/records/data/project/{$record->id}", ['values' => ['name' => '変えた名前']])->assertNotFound();
            $this->delete("/records/data/project/{$record->id}")->assertNotFound();
            $this->assertModelExists($record);
        }

        $this->assertEquals($before, $issue->refresh()->field_values);
    }

    public function test_the_form_request_checks_only_the_envelope_and_writes_nothing(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $count = Record::query()->count();

        $this->post('/records/data/project', ['values' => 'x'])->assertSessionHasErrors(['values' => '値の形が正しくありません']);
        $this->post('/records/data/project', ['details' => ['milestones' => 'x']])->assertSessionHasErrors('details.milestones');
        $this->put("/records/data/project/{$project->id}", ['details' => ['milestones' => [['id' => 'x']]]])
            ->assertSessionHasErrors(['details.milestones.0.id' => '明細の行の指定が正しくありません']);
        $this->put("/records/data/project/{$project->id}", ['details' => ['milestones' => [['values' => 'x']]]])->assertSessionHasErrors('details.milestones.0.values');

        $this->assertSame($count, Record::query()->count());
    }

    public function test_a_null_envelope_is_a_validation_error_and_writes_nothing(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $count = Record::query()->count();

        $this->postJson('/records/data/project', ['values' => null])->assertUnprocessable()->assertJsonValidationErrors('values');
        $this->put("/records/data/project/{$project->id}", ['details' => ['milestones' => '']])->assertSessionHasErrors('details.milestones');
        $this->putJson("/records/data/project/{$project->id}", ['details' => ['milestones' => [null]]])->assertUnprocessable()->assertJsonValidationErrors('details.milestones.0');

        $this->assertSame($count, Record::query()->count());
    }

    public function test_the_write_routes_return_403_and_404_of_another_module_before_checking_the_envelope(): void
    {
        $issue = $this->recordNamed('project_issue', 'title', '検索の応答が遅い');
        $this->deny('project_issue', RecordModuleAction::Create);

        $this->postJson('/records/data/project_issue', ['values' => 'x'])->assertForbidden();
        $this->putJson("/records/data/project/{$issue->id}", ['values' => 'x'])->assertNotFound();
    }

    /**
     * 業務モジュールのいちばん新しいレコード。
     */
    private function latestRecordOf(string $moduleCode): Record
    {
        return Record::query()->where('record_module_id', RecordModule::query()->where('code', $moduleCode)->sole()->id)->latest('id')->firstOrFail();
    }
}
