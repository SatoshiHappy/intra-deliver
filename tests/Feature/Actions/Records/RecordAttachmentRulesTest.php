<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordUploadInput;
use App\Actions\Records\DetachRecordAttachments;
use App\Actions\Upload\Api\StoreUploads;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 添付の口の呼び手の誤りと存在を見せない規則(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節・8 節):
 * 持ち主の鍵はそのレコードの持ち主のものだけ、上げ済みの実体の名前は形だけを当て直す、ほかのレコードの添付は持ち主があっても 404。
 */
final class RecordAttachmentRulesTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use BuildsUploads;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_a_holder_key_without_that_holder_is_a_mistake_and_writes_nothing(): void
    {
        $this->recordModule('held', [RecordModuleAction::Read]);
        $recordId = $this->recordIn('held');
        $this->registerRecordHolds([]);

        foreach ([$this->user()->id, null] as $userId) {
            $attach = fn () => app(AttachRecordUploads::class)
                ->execute($this->contextFor($userId), 'held', $recordId, [$this->uploadedFile('a.pdf')], 'case:1');

            $this->assertThrows($attach, InvalidArgumentException::class);
        }

        $this->assertSame(0, RecordAttachment::query()->count());
        $this->assertSame(0, Upload::query()->count());
        $this->assertSame(0, RecordRevision::query()->where('record_id', $recordId)->count());
    }

    public function test_a_holder_key_of_another_holder_is_rejected_and_the_same_key_attaches_without_update(): void
    {
        $this->recordModule('held', [RecordModuleAction::Read]);
        $recordId = $this->recordIn('held');
        $this->registerRecordHolds([$recordId => 'case:1']);
        $attach = fn (string $holder) => app(AttachRecordUploads::class)
            ->execute($this->contextFor(), 'held', $recordId, [$this->uploadedFile('a.pdf')], $holder);

        $this->assertSame(['record' => self::HOLD_REASON], $this->validationErrors(fn () => $attach('case:2')));

        $attach('case:1');
        $this->assertSame(1, RecordAttachment::query()->where('record_id', $recordId)->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wrongNames(): array
    {
        return [
            'empty' => [''],
            '256 characters' => [str_repeat('あ', 252).'.pdf'],
            'a slash' => ['a/b.pdf'],
            'a backslash' => ['a\\b.pdf'],
            'a control character' => ["a\x01.pdf"],
        ];
    }

    #[DataProvider('wrongNames')]
    public function test_an_upload_input_with_a_wrong_name_is_a_mistake(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecordUploadInput(1, $name);
    }

    public function test_an_upload_input_with_an_extension_out_of_the_table_is_attached(): void
    {
        $this->recordModule('projects');
        [$uploadId] = app(StoreUploads::class)->execute(null, [$this->uploadedFile('a.pdf')]);
        $input = new RecordInput([], [], [], [new RecordUploadInput($uploadId, 'archive.zip')]);

        $recordId = app(CreateRecord::class)->execute($this->contextFor(), 'projects', $input);

        $names = RecordAttachment::query()->where('record_id', $recordId)->pluck('name')->all();
        $this->assertSame(['archive.zip'], $names);
    }

    public function test_another_records_attachment_is_not_found_even_on_a_held_record(): void
    {
        $this->recordModule('projects');
        [$first, $held] = [$this->recordIn('projects'), $this->recordIn('projects')];
        $files = [$this->uploadedFile('a.pdf')];
        app(AttachRecordUploads::class)->execute($this->contextFor(), 'projects', $first, $files);
        $attachmentId = RecordAttachment::query()->where('record_id', $first)->sole()->id;
        $this->registerRecordHolds([$held => 'case:1']);

        $detach = fn () => app(DetachRecordAttachments::class)
            ->execute($this->contextFor(), 'projects', $held, $attachmentId);

        $this->assertThrows($detach, ModelNotFoundException::class);
        $this->assertTrue(RecordAttachment::query()->whereKey($attachmentId)->exists());
    }

    private function recordIn(string $moduleCode): int
    {
        $moduleId = RecordModule::query()->where('code', $moduleCode)->sole()->id;

        return Record::factory()->create(['record_module_id' => $moduleId])->id;
    }
}
