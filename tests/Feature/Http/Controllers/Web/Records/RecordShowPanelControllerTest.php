<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\DetachRecordAttachments;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\FixedRecordShowSection;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\RegistersRecordShowSections;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの詳細のサイドパネルの props(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 2.2 節・3 節・6.1 節):
 * panel・sections・panelContent はどれも遅延の値で、部分の再読み込みでは求めたものだけを作る。panelContent はクエリ panel の欄の中身
 * (before は部分の再読み込みでだけ読み、rows を id で突き合わせて後ろに足す)で、開く URL を入口が足す。can は権限の判定だけ(持ち主は hold)。
 */
final class RecordShowPanelControllerTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsUploads;
    use RegistersRecordHolds;
    use RegistersRecordShowSections;

    private RecordModule $module;

    private Record $record;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $this->module = $this->recordModule('panel_check');
        $this->record = Record::factory()->create(['record_module_id' => $this->module->id]);
    }

    public function test_the_first_response_carries_the_band_and_the_sections_but_no_content_or_section(): void
    {
        $section = $this->registerRecordShowSection($this->listSection('test-notes', '外部参照の一覧', '外部参照', 2));

        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Show')
            ->where('panel.items', [
                [
                    'key' => 'attachments', 'name' => '添付ファイル', 'shortName' => '添付', 'count' => 0,
                    'kind' => 'attachments',
                ],
                ['key' => 'comments', 'name' => 'コメント', 'shortName' => 'コメント', 'count' => 0, 'kind' => 'comments'],
                ['key' => 'revisions', 'name' => '更新履歴', 'shortName' => '更新履歴', 'count' => null, 'kind' => 'revisions'],
                ['key' => 'test-notes', 'name' => '外部参照の一覧', 'shortName' => '外部参照', 'count' => 2, 'kind' => 'section'],
            ])
            ->where('sections', [
                ['code' => 'test-notes', 'heading' => '外部参照の一覧', 'shortHeading' => '外部参照', 'count' => 2],
            ])
            ->where('panelContent', null)
            ->where('can', ['update' => true, 'delete' => true, 'attach' => true, 'apply' => false])
            ->where('hold', null)
        );
        $this->assertSame([1, 0], [$section->presenceCalls, $section->sectionCalls]);
    }

    public function test_a_full_response_with_the_panel_query_reads_the_newest_rows_and_ignores_before(): void
    {
        $ids = $this->attachments(6);

        $this->get($this->url(['panel' => 'attachments', 'before' => (string) $ids[2]]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('panelContent.kind', 'attachments')
                ->where('panelContent.rows', fn ($rows) => array_column($rows->all(), 'id')
                    === array_slice(array_reverse($ids), 0, 5))
                ->where('panelContent.hasMore', true)
            );
    }

    public function test_the_comments_pane_reads_the_newest_rows_and_a_partial_reload_appends_the_rest_by_id(): void
    {
        $writer = User::factory()->create();
        $ids = array_reverse(RecordComment::factory()->count(6)->create([
            'record_module_id' => $this->module->id, 'record_id' => $this->record->id, 'user_id' => $writer->id,
        ])->modelKeys());

        $this->get($this->url(['panel' => 'comments']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('panelContent.kind', 'comments')
                ->where('panelContent.rows', fn ($rows) => array_column($rows->all(), 'id') === array_slice($ids, 0, 5))
                ->where('panelContent.hasMore', true)
                ->where('panelContent.canPost', false)
            );
        $page = $this->partial(['panel' => 'comments', 'before' => (string) $ids[4]], 'panelContent')
            ->assertOk()->viewData('page');

        $this->assertSame([$ids[5]], array_column($page['props']['panelContent']['rows'], 'id'));
        $this->assertContains('panelContent.rows', $page['mergeProps'] ?? []);
        $this->assertContains('panelContent.rows.id', $page['matchPropsOn'] ?? []);
    }

    public function test_a_partial_reload_evaluates_only_the_content_and_appends_the_rows_by_id(): void
    {
        $section = $this->registerRecordShowSection($this->listSection('test-notes', '一覧', '一覧'));
        $ids = array_reverse($this->attachments(6));

        $response = $this->partial(['panel' => 'attachments', 'before' => (string) $ids[4]], 'panelContent');

        $page = $response->assertOk()->viewData('page');
        foreach (['module', 'record', 'can', 'hold', 'panel', 'sections'] as $prop) {
            $this->assertArrayNotHasKey($prop, $page['props']);
        }

        $this->assertSame([$ids[5]], array_column($page['props']['panelContent']['rows'], 'id'));
        $this->assertContains('panelContent.rows', $page['mergeProps'] ?? []);
        $this->assertContains('panelContent.rows.id', $page['matchPropsOn'] ?? []);
        $this->assertSame([0, 0], [$section->presenceCalls, $section->sectionCalls]);
    }

    public function test_opening_a_section_by_a_partial_reload_calls_only_its_section(): void
    {
        $section = $this->registerRecordShowSection($this->listSection('test-notes', '一覧', '一覧'));

        $page = $this->partial(['panel' => 'test-notes'], 'panelContent')->assertOk()->viewData('page');

        $this->assertSame(['section', 'test-notes', 'list'], [
            $page['props']['panelContent']['kind'], $page['props']['panelContent']['code'],
            $page['props']['panelContent']['content']['kind'],
        ]);
        $this->assertSame([0, 1], [$section->presenceCalls, $section->sectionCalls]);
    }

    public function test_a_panel_query_outside_the_band_has_no_content(): void
    {
        $this->registerRecordShowSection($this->listSection('test-notes', '一覧', '一覧'));
        $unused = $this->recordModule('panel_unused');
        $unused->update(['is_attachment_enabled' => false]);
        $bare = Record::factory()->create(['record_module_id' => $unused->id]);

        foreach (['unknown', '<script>', 'test-other'] as $key) {
            $this->get($this->url(['panel' => $key]))
                ->assertInertia(fn (Assert $page) => $page->where('panelContent', null));
        }

        $this->get($this->url().'?panel[]=attachments')
            ->assertInertia(fn (Assert $page) => $page->where('panelContent', null));
        $this->get("/records/data/panel_unused/{$bare->id}?panel=attachments")
            ->assertInertia(fn (Assert $page) => $page->where('panelContent', null)->where('can.attach', false));
    }

    public function test_a_failed_section_keeps_the_detail_and_a_throwing_section_is_a_server_error(): void
    {
        $this->registerRecordShowSection(
            new FixedRecordShowSection('test-failed', '失敗', '失敗', section: ['kind' => 'failed']),
        );

        $this->get($this->url(['panel' => 'test-failed']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('panelContent', ['kind' => 'section', 'code' => 'test-failed', 'content' => ['kind' => 'failed']])
            ->has('record')
        );

        $this->registerRecordShowSection(
            new FixedRecordShowSection('test-throw', '投げる', '投げる', new RuntimeException('節の誤り')),
        );
        $this->get($this->url())->assertServerError();
    }

    public function test_the_rows_carry_the_urls_to_open_the_files(): void
    {
        $this->fakeUploadDisk();
        $this->limitUploads();
        // 1 回目は前の版の無い版(変化を出さない)なので、2 回に分けて足す
        foreach ([['a.png', 'png'], ['b.txt', 'txt']] as [$name, $kind]) {
            $files = ['attachments' => [$this->uploadedFile($name, $kind)]];
            $this->post("{$this->url()}/attachments", $files)->assertStatus(303);
        }

        [$png, $txt] = RecordAttachment::query()->where('record_id', $this->record->id)->orderBy('id')->get()->all();
        $purged = RecordAttachment::factory()->create([
            'record_module_id' => $this->module->id, 'record_id' => $this->record->id,
            'upload_id' => Upload::factory()->purged(),
        ]);
        $base = url($this->url());

        $rows = $this->get($this->url(['panel' => 'attachments']))->viewData('page')['props']['panelContent']['rows'];

        $this->assertSame([
            [null, null],
            ["{$base}/attachments/{$txt->id}", null],
            ["{$base}/attachments/{$png->id}", "{$base}/attachments/{$png->id}/preview"],
        ], array_map(fn (array $row) => [$row['downloadUrl'], $row['previewUrl']], $rows));
        $purged->delete();
        app(DetachRecordAttachments::class)->execute($this->contextFor(), 'panel_check', $this->record->id, $png->id);

        $rows = $this->get($this->url(['panel' => 'revisions']))->viewData('page')['props']['panelContent']['rows'];
        $links = fn (array $row) => array_map(
            fn (array $change) => [$change['change'], $change['downloadUrl'], $change['previewUrl']],
            $row['changes']['attachments'],
        );

        $file = "{$base}/revision-files/{$png->upload_id}";
        $this->assertSame([['removed', $file, "{$file}/preview"]], $links($rows[0]));
        $this->assertSame([['added', null, null]], $links($rows[1]));
    }

    public function test_can_is_the_judgement_only_and_a_held_record_keeps_update_and_delete(): void
    {
        $this->registerRecordHolds([$this->record->id => 'workflow-case:1']);
        $unused = $this->recordModule('panel_unused');
        $unused->update(['is_attachment_enabled' => false]);
        $other = Record::factory()->create(['record_module_id' => $unused->id]);
        $readOnly = $this->recordModule('panel_read', [RecordModuleAction::Read]);
        $readable = Record::factory()->create(['record_module_id' => $readOnly->id]);

        $this->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->where('can', ['update' => true, 'delete' => true, 'attach' => true, 'apply' => false])
            ->where('hold', ['reason' => self::HOLD_REASON])
        );
        $this->get("/records/data/panel_unused/{$other->id}")->assertInertia(fn (Assert $page) => $page
            ->where('can', ['update' => true, 'delete' => true, 'attach' => false, 'apply' => false]));
        $this->get("/records/data/panel_read/{$readable->id}")->assertInertia(fn (Assert $page) => $page
            ->where('can', ['update' => false, 'delete' => false, 'attach' => false, 'apply' => false]));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function url(array $query = []): string
    {
        $url = "/records/data/panel_check/{$this->record->id}";

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * 部分の再読み込み(Inertia の画面が送る見出し = inertia-laravel の Testing\ReloadRequest と同じ)。
     *
     * @param  array<string, string>  $query
     * @return TestResponse<Response>
     */
    private function partial(array $query, string $only): TestResponse
    {
        return $this->get($this->url($query), [
            Header::PARTIAL_COMPONENT => 'Records/Show',
            Header::PARTIAL_ONLY => $only,
        ]);
    }

    /**
     * @return list<int> 足した添付の id(古い順)
     */
    private function attachments(int $count): array
    {
        $attachments = RecordAttachment::factory()->count($count)
            ->create(['record_module_id' => $this->module->id, 'record_id' => $this->record->id]);

        return array_values($attachments->pluck('id')->all());
    }
}
