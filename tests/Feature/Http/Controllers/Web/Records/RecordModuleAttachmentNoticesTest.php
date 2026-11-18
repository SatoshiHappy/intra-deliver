<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\FixedRecordHoldNotices;
use Tests\Support\RegistersRecordHoldNotices;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールの編集の画面の、添付の許可を外す前の確かめの文(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 2 節の表の
 * 「案内の文」の列): 親の無い業務モジュールで今の値が許可するときだけ持ち主の案内に聞き、ほかは聞かずに空。案内の失敗は空(確かめずに送る)。
 * 文の数(進行中の申請の 0・1・2 件)は実装の側の検査。初期データの業務モジュールを u0004(システム管理者)で開く。
 */
final class RecordModuleAttachmentNoticesTest extends TransactionalTestCase
{
    use RegistersRecordHoldNotices;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
    }

    public function test_a_module_that_allows_attachments_carries_the_texts_of_the_notices(): void
    {
        $module = RecordModule::query()->where('code', 'project')->sole();
        $notices = $this->registerRecordHoldNotices(
            FixedRecordHoldNotices::of(attachment: fn () => '進行中の申請が 2 件あります。'),
        );

        $this->get('/records/admin/modules/project/edit')->assertInertia(fn (Assert $page) => $page
            ->where('module.isAttachmentEnabled', true)
            ->where('attachmentDisableNotices', ['進行中の申請が 2 件あります。']));

        $this->assertSame([$module->id], $notices->attachmentCalls);
    }

    public function test_a_module_that_does_not_allow_attachments_does_not_ask(): void
    {
        RecordModule::query()->where('code', 'project')->update(['is_attachment_enabled' => false]);
        $notices = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(attachment: fn () => '出ない文'));

        $this->get('/records/admin/modules/project/edit')
            ->assertInertia(fn (Assert $page) => $page->where('attachmentDisableNotices', []));

        $this->assertSame([], $notices->attachmentCalls);
    }

    public function test_a_details_module_does_not_ask(): void
    {
        $details = RecordModule::query()->whereNotNull('parent_record_module_id')->orderBy('id')->firstOrFail();
        $notices = $this->registerRecordHoldNotices(FixedRecordHoldNotices::of(attachment: fn () => '出ない文'));

        $this->get("/records/admin/modules/{$details->code}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('attachmentDisableNotices', []));

        $this->assertSame([], $notices->attachmentCalls);
    }

    public function test_a_failed_notice_gives_no_text(): void
    {
        Exceptions::fake();
        $this->registerRecordHoldNotices(
            FixedRecordHoldNotices::of(attachment: fn () => throw new RuntimeException('test-throw')),
        );

        $this->get('/records/admin/modules/project/edit')
            ->assertInertia(fn (Assert $page) => $page->where('attachmentDisableNotices', []));

        Exceptions::assertReportedCount(1);
    }
}
