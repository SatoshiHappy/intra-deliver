<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRecordModules;
use Tests\Support\FixedRecordHolds;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 業務の画面と持ち主(docs の 設計/ワークフロー/業務データとの接続/README.md 4 節・9 節の「持ち主」、設計/業務データの入れ物/画面/業務の画面.md 2.2・2.3、
 * 認可とメニュー/README.md 2.2、メニューと初期データ.md 8 節): 鍵は HTTP から来ない(更新・削除の入力に holder を送っても無視されて 422 record)。詳細の can は
 * 持ち主があっても権限の判定のまま(画面が hold で押せない形にする = レコードの共通機能/詳細の画面/README.md の 5.1 節)、詳細と編集の hold に持ち主の理由が出る。
 * テストの持ち主の実装をタグで登録し、業務モジュールとレコードはテストで作る。
 */
final class RecordHoldControllerTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RegistersRecordHolds;

    private const HOLDER = 'workflow-case:1';

    private RecordModule $module;

    private RecordField $title;

    private Record $held;

    private Record $free;

    private FixedRecordHolds $recordHolds;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $this->module = $this->recordModule('hold_check');
        $this->title = $this->field($this->module, 'title');
        $this->held = Record::factory()->create(['record_module_id' => $this->module->id, 'field_values' => [$this->title->id => '旧']]);
        $this->free = Record::factory()->create(['record_module_id' => $this->module->id, 'field_values' => [$this->title->id => '旧']]);
        $this->recordHolds = $this->registerRecordHolds([$this->held->id => self::HOLDER]);
    }

    public function test_the_detail_of_a_held_record_keeps_update_and_delete_and_shows_the_reason(): void
    {
        $this->get("/records/data/hold_check/{$this->held->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Show')
            ->where('can', ['update' => true, 'delete' => true, 'attach' => true])
            ->where('hold', ['reason' => self::HOLD_REASON])
        );
    }

    /**
     * 詳細の 1 描画で持ち主を読むのは 1 回(hold だけ。添付ファイルの欄を開いていないとき)。
     */
    public function test_the_detail_reads_the_holder_once_per_render(): void
    {
        $this->get("/records/data/hold_check/{$this->held->id}")->assertOk();

        $this->assertSame(1, $this->recordHolds->holdsOfCalls());
    }

    public function test_the_detail_of_a_record_without_a_holder_is_as_before(): void
    {
        $this->get("/records/data/hold_check/{$this->free->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can', ['update' => true, 'delete' => true, 'attach' => true])
            ->where('hold', null)
        );
    }

    public function test_the_edit_page_carries_the_hold(): void
    {
        $this->get("/records/data/hold_check/{$this->held->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Edit')
            ->where('hold', ['reason' => self::HOLD_REASON])
        );
        $this->get("/records/data/hold_check/{$this->free->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->where('hold', null));
    }

    public function test_updating_a_held_record_ignores_a_holder_in_the_input_and_is_rejected(): void
    {
        $this->putJson("/records/data/hold_check/{$this->held->id}", ['values' => ['title' => '新'], 'holder' => self::HOLDER, 'version' => $this->held->version()])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['record' => [self::HOLD_REASON]]);

        $this->assertEquals([$this->title->id => '旧'], $this->held->refresh()->field_values);
    }

    public function test_deleting_a_held_record_ignores_a_holder_in_the_input_and_is_rejected(): void
    {
        $this->deleteJson("/records/data/hold_check/{$this->held->id}", ['holder' => self::HOLDER])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['record' => [self::HOLD_REASON]]);

        $this->assertModelExists($this->held);
    }

    public function test_a_record_without_a_holder_is_updated_and_deleted_as_before(): void
    {
        $this->putJson("/records/data/hold_check/{$this->free->id}", ['values' => ['title' => '新'], 'version' => $this->free->version()])->assertStatus(303);
        $this->assertEquals([$this->title->id => '新'], $this->free->refresh()->field_values);

        $this->deleteJson("/records/data/hold_check/{$this->free->id}")->assertStatus(303);
        $this->assertModelMissing($this->free);
    }
}
