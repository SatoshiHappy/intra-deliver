<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\FixedRecordScreenAddons;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\RegistersRecordScreenAddons;
use Tests\Support\TransactionalTestCase;

/**
 * 業務の一覧とレコードの詳細に後ろのモジュールが足す操作・札・注(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5):
 * 足す先が今の業務モジュールに合う実装だけを登録の順に呼び、行の札には今のページの見える行だけを渡す。閲覧できない見る人では詳細の
 * show() を呼ばず、部分の再読み込みでは求められたものだけを作る(形の誤り = RecordScreenAddonsShapeTest)。テストの実装をタグで登録し、今日を 2026-10-11 に固定する。
 */
final class RecordScreenAddonsControllerTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use RegistersRecordHolds;
    use RegistersRecordScreenAddons;

    private const BADGE = ['label' => '処理中', 'tone' => 'pending'];

    private RecordModule $module;

    /** @var list<int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $this->module = $this->recordModule('addon_check');
        $this->ids = $this->records($this->module, 3);
    }

    public function test_the_list_carries_the_actions_and_the_row_badges_of_the_implementations_in_order(): void
    {
        [$first, $second, $third] = $this->ids;
        $this->registerRecordScreenAddons(new FixedRecordScreenAddons(
            'addon_check',
            ['actions' => [self::addonAction('create'), self::addonAction('import')]],
            [$first => [self::BADGE], $second => []],
        ));
        $this->registerRecordScreenAddons(new FixedRecordScreenAddons(
            'addon_check',
            ['actions' => [self::addonAction('export')]],
            [$first => [['label' => '失敗', 'tone' => 'rejected']], $third => [['label' => '注', 'tone' => null]]],
        ));
        $other = $this->registerRecordScreenAddons($this->otherModuleAddons());

        $this->get('/records/data/addon_check')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Index')
            ->where('addons.actions', [
                self::addonAction('create'), self::addonAction('import'), self::addonAction('export'),
            ])
            ->where('addonBadges', [
                $first => [self::BADGE, ['label' => '失敗', 'tone' => 'rejected']],
                $third => [['label' => '注', 'tone' => null]],
            ])
        );
        $this->assertSame([0, []], [$other->indexCalls, $other->badgeCalls]);
    }

    public function test_the_detail_carries_the_actions_the_badges_and_the_field_notes_of_the_implementations(): void
    {
        $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_check', show: [
            'actions' => [self::addonAction('recreate', ['changesValues' => true])],
            'badges' => [self::BADGE],
            'fieldNotes' => ['summary' => '再作成で上書きされます'],
        ]));
        $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_check', show: [
            'actions' => [self::addonAction('share', ['disabledReason' => '処理中です'])],
            'badges' => [],
            'fieldNotes' => ['summary' => '後の注', 'minutes' => '議事録の注'],
        ]));
        $other = $this->registerRecordScreenAddons($this->otherModuleAddons());

        $this->get("/records/data/addon_check/{$this->ids[0]}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Show')
            ->where('addons', [
                'actions' => [
                    self::addonAction('recreate', ['changesValues' => true]),
                    self::addonAction('share', ['disabledReason' => '処理中です']),
                ],
                'badges' => [self::BADGE],
                'fieldNotes' => ['summary' => '再作成で上書きされます', 'minutes' => '議事録の注'],
            ])
        );
        $this->assertSame(0, $other->showCalls);
    }

    public function test_the_row_badges_receive_only_the_rows_the_viewer_sees(): void
    {
        $fixture = $this->rowAccessFixture();
        $addons = $this->registerRecordScreenAddons(new FixedRecordScreenAddons(
            'meetings',
            badges: [$fixture['recordId'] => [self::BADGE], $fixture['otherId'] => [self::BADGE]],
        ));

        $this->actingAs($fixture['people']['creator'])->get('/records/data/meetings')
            ->assertInertia(fn (Assert $page) => $page->where('addonBadges', [$fixture['recordId'] => [self::BADGE]]));
        $this->actingAs($fixture['people']['watcher'])->get('/records/data/meetings')
            ->assertInertia(fn (Assert $page) => $page->where('addonBadges', []));

        // 見える行が無いページでは呼ばない
        $this->assertSame([[$fixture['recordId']]], $addons->badgeCalls);
    }

    public function test_a_page_without_rows_does_not_call_badges(): void
    {
        $this->recordModule('addon_empty');
        $empty = $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_empty'));
        $paged = $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_check'));

        $page = $this->get('/records/data/addon_empty')->assertOk()->viewData('page');
        // 3 件の業務モジュールの 2 ページ目 = 行の無いページ
        $reload = $this->partial('/records/data/addon_check?page=2', 'Records/Index', 'records,query,addonBadges')
            ->assertOk()->viewData('page');

        $this->assertSame([[], '{}', '{}'], [
            $reload['props']['records']['data'],
            json_encode($page['props']['addonBadges']),
            json_encode($reload['props']['addonBadges']),
        ]);
        $this->assertSame([[], []], [$empty->badgeCalls, $paged->badgeCalls]);
    }

    public function test_the_maps_arrive_as_json_objects_with_or_without_entries(): void
    {
        $this->registerRecordScreenAddons(new FixedRecordScreenAddons(
            'addon_check',
            badges: [$this->ids[0] => [self::BADGE]],
            show: ['actions' => [], 'badges' => [], 'fieldNotes' => ['length' => '注']],
        ));
        $bare = $this->recordModule('addon_bare');
        [$bareId] = $this->records($bare, 1);
        $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_bare'));

        $listed = $this->get('/records/data/addon_check')->viewData('page')['props'];
        $shown = $this->get("/records/data/addon_check/{$this->ids[0]}")->viewData('page')['props'];
        $bareListed = $this->get('/records/data/addon_bare')->viewData('page')['props'];
        $bareShown = $this->get("/records/data/addon_bare/{$bareId}")->viewData('page')['props'];

        $this->assertSame('{"'.$this->ids[0].'":[{"label":"処理中","tone":"pending"}]}', json_encode(
            $listed['addonBadges'],
            JSON_UNESCAPED_UNICODE,
        ));
        $this->assertSame('{"length":"注"}', json_encode($shown['addons']['fieldNotes'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(['{}', '{}'], [
            json_encode($bareListed['addonBadges']), json_encode($bareShown['addons']['fieldNotes']),
        ]);
    }

    public function test_a_viewer_who_cannot_open_the_detail_does_not_call_show(): void
    {
        $fixture = $this->rowAccessFixture();
        $related = $this->registerRecordScreenAddons(new FixedRecordScreenAddons('meetings'));
        $unreadable = $this->recordModule('addon_unreadable', [RecordModuleAction::Create]);
        [$hiddenId] = $this->records($unreadable, 1);
        $closed = $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_unreadable'));

        $this->actingAs($fixture['people']['watcher'])
            ->get("/records/data/meetings/{$fixture['recordId']}")->assertNotFound();
        $this->get("/records/data/addon_unreadable/{$hiddenId}")->assertForbidden();
        $this->get('/records/data/addon_unreadable')->assertForbidden();

        $this->assertSame(
            [0, 0, 0, []],
            [$related->showCalls, $closed->showCalls, $closed->indexCalls, $closed->badgeCalls],
        );
    }

    public function test_a_list_reload_builds_the_badges_but_not_the_actions(): void
    {
        $addons = $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_check'));

        $page = $this->partial('/records/data/addon_check?sort=id', 'Records/Index', 'records,query,addonBadges')
            ->assertOk()->viewData('page');

        $this->assertArrayNotHasKey('addons', $page['props']);
        $this->assertArrayHasKey('addonBadges', $page['props']);
        $this->assertSame([0, [$this->ids]], [$addons->indexCalls, $addons->badgeCalls]);
    }

    public function test_a_side_panel_reload_does_not_call_show(): void
    {
        $addons = $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_check'));
        $url = "/records/data/addon_check/{$this->ids[0]}?panel=attachments";

        foreach (['panel,panelContent', 'panelContent'] as $only) {
            $page = $this->partial($url, 'Records/Show', $only)->assertOk()->viewData('page');
            $this->assertArrayNotHasKey('addons', $page['props']);
        }

        $this->assertSame(0, $addons->showCalls);
    }

    public function test_search_sort_and_page_reloads_put_the_badges_on_the_new_rows(): void
    {
        $title = $this->field($this->module, 'title', attributes: ['is_title' => true, 'is_searchable' => true]);
        $found = Record::factory()
            ->create(['record_module_id' => $this->module->id, 'field_values' => [$title->id => '定例']]);
        $more = $this->records($this->module, 18);
        $all = [...$this->ids, $found->id, ...$more];
        $badges = array_fill_keys($all, [self::BADGE]);
        $addons = $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_check', badges: $badges));
        $only = 'records,query,addonBadges';

        $searched = $this->partial('/records/data/addon_check?filter[title]=定例', 'Records/Index', $only);
        $sorted = $this->partial('/records/data/addon_check?sort=id', 'Records/Index', $only);
        $paged = $this->partial('/records/data/addon_check?page=2', 'Records/Index', $only);

        $this->assertSame([$found->id], array_keys((array) $searched->viewData('page')['props']['addonBadges']));
        $this->assertSame(
            array_slice($all, 0, 20),
            array_keys((array) $sorted->viewData('page')['props']['addonBadges']),
        );
        $this->assertSame([$all[0], $all[1]], array_keys((array) $paged->viewData('page')['props']['addonBadges']));
        $this->assertSame([[$found->id], array_slice($all, 0, 20), [$all[1], $all[0]]], $addons->badgeCalls);
    }

    public function test_a_module_without_implementations_has_no_actions_and_no_badges(): void
    {
        $this->registerRecordScreenAddons($this->otherModuleAddons());

        $this->get('/records/data/addon_check')->assertInertia(fn (Assert $page) => $page
            ->where('addons', ['actions' => []])
            ->where('addonBadges', []));
        $this->get("/records/data/addon_check/{$this->ids[0]}")->assertInertia(fn (Assert $page) => $page
            ->where('addons', ['actions' => [], 'badges' => [], 'fieldNotes' => []]));
    }

    public function test_a_held_record_still_carries_the_actions(): void
    {
        $this->registerRecordHolds([$this->ids[0] => 'workflow-case:1']);
        $action = self::addonAction('recreate', ['changesValues' => true]);
        $addons = $this->registerRecordScreenAddons(new FixedRecordScreenAddons('addon_check', show: [
            'actions' => [$action], 'badges' => [], 'fieldNotes' => [],
        ]));

        $this->get("/records/data/addon_check/{$this->ids[0]}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hold', ['reason' => self::HOLD_REASON])
            ->where('addons.actions', [$action]));
        $this->assertSame(1, $addons->showCalls);
    }

    /**
     * 足す先の違う実装(何を呼ばれても足す)。
     */
    private function otherModuleAddons(): FixedRecordScreenAddons
    {
        return new FixedRecordScreenAddons(
            'addon_other',
            ['actions' => [self::addonAction('other')]],
            show: ['actions' => [self::addonAction('other')], 'badges' => [self::BADGE], 'fieldNotes' => []],
        );
    }

    /**
     * @return list<int> 作ったレコードの id(古い順)
     */
    private function records(RecordModule $module, int $count): array
    {
        $records = Record::factory()->count($count)->create(['record_module_id' => $module->id]);

        return array_values($records->pluck('id')->all());
    }

    /**
     * 部分の再読み込み(Inertia の画面が送る見出し = inertia-laravel の Testing\ReloadRequest と同じ)。
     *
     * @return TestResponse<Response>
     */
    private function partial(string $url, string $component, string $only): TestResponse
    {
        return $this->get($url, [Header::PARTIAL_COMPONENT => $component, Header::PARTIAL_ONLY => $only]);
    }
}
