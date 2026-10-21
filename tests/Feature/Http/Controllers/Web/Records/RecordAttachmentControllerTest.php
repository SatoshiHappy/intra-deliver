<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\PurgeRecordMedia;
use App\Actions\Records\DetachRecordAttachments;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Queries\Upload\Api\UploadLimits;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 添付と外した時点のファイルのルート(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 8 節・
 * 更新履歴.md の 5.4 節、判定の順 = 認可と初期データ.md の 1.1 節、検査 = 認可と初期データ.md の 5 節の「ルート」・レコードの添付.md の 10 節の
 * 「判定」)。ルートの認可を外さない(初期データのポリシー)。今日を 2026-10-11 に固定する。
 */
final class RecordAttachmentControllerTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use BuildsUploads;

    private const PAGE = '/records/data/projects/1';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_adding_and_removing_return_to_the_page_with_a_toast(): void
    {
        $recordId = $this->projectRecord();
        $url = "/records/data/projects/{$recordId}/attachments";

        $files = [$this->uploadedFile('a.pdf'), $this->uploadedFile('b.png', 'png')];
        $this->from(self::PAGE)->post($url, ['attachments' => $files])
            ->assertStatus(303)->assertRedirect(self::PAGE)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'ファイルを追加しました']);
        $attachment = RecordAttachment::query()->where('record_id', $recordId)->orderBy('id')->firstOrFail();
        $this->from(self::PAGE)->delete("{$url}/{$attachment->id}")
            ->assertStatus(303)->assertRedirect(self::PAGE)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'ファイルを削除しました']);

        $this->assertSame(['b.png'], RecordAttachment::query()->where('record_id', $recordId)->pluck('name')->all());
    }

    public function test_the_input_errors_use_the_keys_and_messages_of_the_upload_rules(): void
    {
        $url = "/records/data/projects/{$this->projectRecord()}/attachments";
        $pdf = $this->uploadedFile('a.pdf');
        $tooLarge = new UploadedFile($pdf->getPathname(), 'a.pdf', null, UPLOAD_ERR_INI_SIZE, true);
        $four = array_map(fn (int $index) => $this->uploadedFile("{$index}.pdf"), range(1, 4));
        $source = $this->uploadedFile('src.png', 'png');

        $this->from(self::PAGE)->post($url, ['attachments' => [$tooLarge]])
            ->assertSessionHasErrors(['attachments.0' => 'ファイルの大きさは 1000B までです']);
        $this->from(self::PAGE)->post($url, ['attachments' => $four])
            ->assertSessionHasErrors(['attachments' => UploadLimits::TOO_MANY_FILES]);
        $this->from(self::PAGE)->post($url, [])->assertSessionHasErrors(['attachments' => UploadLimits::NO_FILES]);
        $pngAsPdf = new UploadedFile($source->getPathname(), 'a.pdf', null, null, true);
        $this->from(self::PAGE)->post($url, ['attachments' => [$pngAsPdf]])
            ->assertSessionHasErrors(['attachments.0' => UploadLimits::UNSUPPORTED_FORMAT]);
        $this->assertSame(0, RecordAttachment::query()->count());
    }

    public function test_an_element_that_is_not_a_file_is_not_received_and_empty_fields_are_dropped(): void
    {
        $url = "/records/data/projects/{$this->projectRecord()}/attachments";
        // 空のファイル欄(PHP が送る形。Symfony の FileBag が null にし、Laravel が除く)
        $noFile = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $referer = ['HTTP_REFERER' => self::PAGE];

        $this->from(self::PAGE)->post($url, ['attachments' => [['x' => $this->uploadedFile('a.pdf')]]])
            ->assertSessionHasErrors(['attachments.0' => UploadLimits::NOT_RECEIVED]);
        $this->call('POST', $url, [], [], ['attachments' => [$noFile]], $referer)
            ->assertSessionHasErrors(['attachments' => UploadLimits::NO_FILES]);
        $this->call('POST', $url, [], [], ['attachments' => [$this->uploadedFile('b.pdf'), $noFile]], $referer)
            ->assertStatus(303);

        $this->assertSame(['b.pdf'], RecordAttachment::query()->pluck('name')->all());
    }

    public function test_a_reader_cannot_add_or_remove_and_an_updater_adds_without_reading(): void
    {
        $readonly = $this->recordIn($this->recordModule('readonly', [RecordModuleAction::Read])->id);
        $writeonly = $this->recordIn($this->recordModule('writeonly', [RecordModuleAction::Update])->id);
        $file = fn () => ['attachments' => [$this->uploadedFile('a.pdf')]];

        $this->post("/records/data/readonly/{$readonly}/attachments", $file())->assertForbidden();
        $this->delete("/records/data/readonly/{$readonly}/attachments/1")->assertForbidden();
        $this->from(self::PAGE)->post("/records/data/writeonly/{$writeonly}/attachments", $file())->assertStatus(303);
        $attachmentId = RecordAttachment::query()->where('record_id', $writeonly)->sole()->id;
        $this->get("/records/data/writeonly/{$writeonly}/attachments/{$attachmentId}")->assertForbidden();
    }

    public function test_the_order_is_module_404_then_403_then_record_404_then_attachment_404(): void
    {
        $recordId = $this->projectRecord();
        $other = $this->projectRecord();
        $attachmentId = $this->attach($recordId, 'a.pdf');
        $this->recordModule('readonly', [RecordModuleAction::Read]);

        $this->recordModule('secret', [RecordModuleAction::Update]);
        $input = fn () => ['attachments' => [$this->uploadedFile('a.pdf')]];

        $this->post('/records/data/missing/1/attachments', $input())->assertNotFound();
        $this->post('/records/data/readonly/999999999/attachments', $input())->assertForbidden();
        $this->get('/records/data/secret/999999999/attachments/1')->assertForbidden();
        $this->post('/records/data/projects/999999999/attachments', $input())->assertNotFound();
        $this->get('/records/data/projects/999999999/attachments/1')->assertNotFound();
        $this->get("/records/data/projects/{$other}/attachments/{$attachmentId}")->assertNotFound();
        $this->delete("/records/data/projects/{$other}/attachments/{$attachmentId}")->assertNotFound();
        $this->get("/records/data/projects/{$recordId}/attachments/abc")->assertNotFound();
        $this->assertSame(1, RecordAttachment::query()->count());
    }

    public function test_a_download_and_a_preview_answer_with_the_headers_and_ranges(): void
    {
        $recordId = $this->projectRecord();
        $pdf = $this->attach($recordId, '見積書.pdf');
        $text = $this->attach($recordId, 'memo.txt', 'txt');
        $url = "/records/data/projects/{$recordId}/attachments";
        $size = strlen($this->sampleContent('pdf'));
        $disposition = "attachment; filename=.pdf; filename*=utf-8''".rawurlencode('見積書.pdf');

        $this->get("{$url}/{$pdf}")->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', $disposition);
        $this->get("{$url}/{$pdf}", ['Range' => 'bytes=0-9'])
            ->assertStatus(206)->assertHeader('Content-Range', "bytes 0-9/{$size}");
        $this->get("{$url}/{$pdf}", ['Range' => 'bytes=1000-2000'])->assertStatus(416);
        $this->get("{$url}/{$pdf}/preview")->assertOk()->assertHeader('Content-Disposition', 'inline');
        $this->get("{$url}/{$text}/preview")->assertNotFound();
        $this->get("{$url}/{$text}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_audio_plays_with_ranges_and_a_file_purged_by_its_due_date_is_gone_from_both_routes(): void
    {
        $recordId = $this->projectRecord();
        $module = RecordModule::query()->where('code', 'projects')->sole();
        $due = $this->field($module, 'purge_on', RecordFieldType::Date);
        $audio = $this->attach($recordId, 'a.mp3', 'mp3');
        $pdf = $this->attach($recordId, 'b.pdf');
        $url = "/records/data/projects/{$recordId}/attachments";

        $this->get("{$url}/{$audio}/preview", ['Range' => 'bytes=0-9'])->assertStatus(206)
            ->assertHeader('Content-Type', 'audio/mpeg')->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->get("{$url}/{$audio}/preview", ['Range' => 'bytes=100000-200000'])->assertStatus(416);
        Record::query()->findOrFail($recordId)->update(['field_values' => [$due->id => '2026-10-10']]);
        app(PurgeRecordMedia::class)->execute('projects', $recordId, 'purge_on', now());

        $this->get("{$url}/{$audio}")->assertNotFound();
        $this->get("{$url}/{$audio}/preview")->assertNotFound();
        $this->get("{$url}/{$pdf}/preview")->assertOk();
        $this->get("{$url}/{$pdf}")->assertOk();
    }

    public function test_an_unrelated_person_gets_404_and_a_related_person_opens_the_file(): void
    {
        $fixture = $this->rowAccessFixture();
        $recordId = $fixture['recordId'];
        $creator = $this->contextFor($fixture['people']['creator']->id);
        app(AttachRecordUploads::class)->execute($creator, 'meetings', $recordId, [$this->uploadedFile('a.pdf')]);
        $attachmentId = RecordAttachment::query()->where('record_id', $recordId)->sole()->id;
        $url = "/records/data/meetings/{$recordId}/attachments/{$attachmentId}";

        $this->actingAs($fixture['people']['watcher'])->get($url)->assertNotFound();
        $this->actingAs($fixture['people']['attendee'])->get($url)->assertOk();
    }

    public function test_a_file_removed_from_the_record_opens_from_the_revisions_only_on_that_record(): void
    {
        $recordId = $this->projectRecord();
        $other = $this->projectRecord();
        $attachmentId = $this->attach($recordId, '旧版.pdf');
        $uploadId = RecordAttachment::query()->findOrFail($attachmentId)->upload_id;
        app(DetachRecordAttachments::class)->execute($this->contextFor(), 'projects', $recordId, $attachmentId);
        $current = RecordAttachment::query()->findOrFail($this->attach($other, 'b.pdf'))->upload_id;
        $readonly = $this->recordIn($this->recordModule('secret', [RecordModuleAction::Update])->id);

        $this->get("/records/data/projects/{$recordId}/revision-files/{$uploadId}")->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename=.pdf; filename*=utf-8''%E6%97%A7%E7%89%88.pdf");
        $this->get("/records/data/projects/{$recordId}/revision-files/{$uploadId}/preview")->assertOk();
        $this->get("/records/data/projects/{$other}/revision-files/{$uploadId}")->assertNotFound();
        $this->get("/records/data/projects/{$recordId}/revision-files/{$current}")->assertNotFound();
        $this->get("/records/data/projects/{$recordId}/revision-files/999999999")->assertNotFound();
        $this->get("/records/data/secret/{$readonly}/revision-files/{$uploadId}")->assertForbidden();
    }

    private function projectRecord(): int
    {
        $module = RecordModule::query()->where('code', 'projects')->first() ?? $this->recordModule('projects');

        return $this->recordIn($module->id);
    }

    private function recordIn(int $moduleId): int
    {
        return Record::factory()->create(['record_module_id' => $moduleId])->id;
    }

    /**
     * 業務処理で 1 つ付けて、その添付の id を返す。
     */
    private function attach(int $recordId, string $name, string $kind = 'pdf'): int
    {
        $files = [$this->uploadedFile($name, $kind)];
        app(AttachRecordUploads::class)->execute($this->contextFor(), 'projects', $recordId, $files);

        return RecordAttachment::query()->where('record_id', $recordId)->orderByDesc('id')->firstOrFail()->id;
    }
}
