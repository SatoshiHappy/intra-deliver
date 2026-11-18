<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Records\Api\RecordHoldLink;
use App\Queries\Records\Api\RecordHoldState;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRecordModules;
use Tests\Support\FixedRecordHoldNotices;
use Tests\Support\RegistersRecordHoldNotices;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 持ち主の案内を業務データの入れ物の画面に出す所(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1・
 * 更新履歴.md の 5.1・業務モジュールの管理.md の 2 節の表の、業務データの入れ物の側の行): 案内は持ち主の鍵・版・業務モジュールから引き、
 * 申請を閲覧できるかは実装が判定する(ここではテストの実装が返す値で、返る・返らないを通す)。書き込みの経路は案内を呼ばない。
 */
final class RecordHoldNoticeControllerTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RegistersRecordHoldNotices;
    use RegistersRecordHolds;

    private const HOLDER = 'workflow-case:1';

    private RecordModule $module;

    private Record $held;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $this->module = $this->recordModule('notice_check');
        $this->held = Record::factory()->create(['record_module_id' => $this->module->id]);
        $this->registerRecordHolds([$this->held->id => self::HOLDER]);
    }

    public function test_the_detail_and_the_edit_page_carry_the_state_when_the_implementation_returns_it(): void
    {
        $notices = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(state: fn () => new RecordHoldState(
            new RecordHoldLink('WF-000012', '/workflow/cases/12'), '承認待ち', 'pending', '部長(保留中)',
        )));
        $hold = ['reason' => self::HOLD_REASON, 'state' => [
            'link' => ['label' => 'WF-000012', 'url' => '/workflow/cases/12'],
            'status' => '承認待ち', 'tone' => 'pending', 'suffix' => '部長(保留中)',
        ]];

        foreach (['', '/edit'] as $page) {
            $this->get("{$this->url()}{$page}")->assertInertia(fn (Assert $inertia) => $inertia->where('hold', $hold));
        }

        $this->assertSame([[null, self::HOLDER, '2026-10-11'], [null, self::HOLDER, '2026-10-11']], array_map(
            fn (array $call) => [$call[0]->userId, $call[1], $call[2]->format('Y-m-d')],
            $notices->stateCalls,
        ));
    }

    public function test_the_state_is_asked_as_the_signed_in_user(): void
    {
        $user = User::query()->where('code', 'u0004')->sole();
        $notices = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of());
        $this->actingAs($user);

        $this->get($this->url())->assertOk();

        $this->assertSame([$user->id], array_map(fn (array $call) => $call[0]->userId, $notices->stateCalls));
    }

    public function test_only_the_reason_is_shown_when_the_implementation_returns_no_state(): void
    {
        $this->registerRecordHoldNotices(FixedRecordHoldNotices::of());

        $hold = ['reason' => self::HOLD_REASON, 'state' => null];

        foreach (['', '/edit'] as $page) {
            $this->get("{$this->url()}{$page}")->assertInertia(fn (Assert $inertia) => $inertia->where('hold', $hold));
        }
    }

    public function test_a_record_without_a_holder_does_not_ask_the_state(): void
    {
        $notices = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of());
        $free = Record::factory()->create(['record_module_id' => $this->module->id]);

        $this->get("/records/data/notice_check/{$free->id}")
            ->assertInertia(fn (Assert $inertia) => $inertia->where('hold', null));

        $this->assertSame([], $notices->stateCalls);
    }

    public function test_the_revision_rows_with_an_origin_carry_the_link_and_the_notices_are_asked_once(): void
    {
        $of = ['record_module_id' => $this->module->id, 'record_id' => $this->held->id];
        $plain = RecordRevision::factory()->create([...$of, 'revision' => 1]);
        $linked = RecordRevision::factory()->update()->fromOperation('申請', null)->create([...$of, 'revision' => 2]);
        $unlinked = RecordRevision::factory()->update()->fromOperation()->create([...$of, 'revision' => 3]);
        $notices = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(
            revisions: fn () => [$linked->id => new RecordHoldLink('WF-000012', '/workflow/cases/12')],
        ));

        $rows = $this->get("{$this->url()}?panel=revisions")->viewData('page')['props']['panelContent']['rows'];
        $linkOf = fn (array $row) => [$row['id'], $row['origin'] === null ? 'no origin' : $row['origin']['link']];

        $this->assertSame([
            [$unlinked->id, null],
            [$linked->id, ['label' => 'WF-000012', 'url' => '/workflow/cases/12']],
            [$plain->id, 'no origin'],
        ], array_map($linkOf, $rows));
        $this->assertSame([[$this->held->id, [$unlinked->id, $linked->id]]], $notices->revisionCalls);
    }

    public function test_the_write_path_does_not_ask_the_notices(): void
    {
        $notices = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of());

        $this->putJson($this->url(), ['values' => [], 'version' => $this->held->version()])->assertStatus(422);
        $this->deleteJson($this->url())->assertStatus(422);

        $this->assertSame([[], [], []], [$notices->stateCalls, $notices->revisionCalls, $notices->attachmentCalls]);
    }

    private function url(): string
    {
        return "/records/data/notice_check/{$this->held->id}";
    }
}
