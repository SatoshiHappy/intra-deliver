<?php

namespace Tests\Feature\Models\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの添付(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.3 節・同じ階層の README.md の 7 節:
 * 添付の行があるレコードは DELETE できない、1 つの実体は 1 つの添付だけに付く、名前は空にしない、
 * 添付が指す台帳の行は消せない、別の業務モジュールのレコードを指せない)。
 * 外部キーの削除の動作とリレーションは ForeignKeysTest。
 */
final class RecordAttachmentTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_deleting_a_record_that_has_an_attachment(): void
    {
        $attachment = RecordAttachment::factory()->create();

        $this->assertRejectedBy(
            'record_attachments_record_module_id_record_id_foreign',
            fn () => DB::table('records')->where('id', $attachment->record_id)->delete(),
        );
        $this->assertModelExists($attachment);
    }

    public function test_deleting_the_attachment_first_lets_the_record_be_deleted(): void
    {
        $attachment = RecordAttachment::factory()->create();

        $attachment->delete();
        DB::table('records')->where('id', $attachment->record_id)->delete();

        $this->assertDatabaseMissing('records', ['id' => $attachment->record_id]);
    }

    public function test_rejects_the_same_upload_on_two_attachments(): void
    {
        $attachment = RecordAttachment::factory()->create();

        $this->assertRejectedBy(
            'record_attachments_upload_id_unique',
            fn () => RecordAttachment::factory()->create(['upload_id' => $attachment->upload_id]),
        );
    }

    public function test_rejects_an_empty_name(): void
    {
        $this->assertRejectedBy(
            'record_attachments_name_check',
            fn () => RecordAttachment::factory()->create(['name' => '']),
        );
    }

    public function test_rejects_deleting_an_upload_that_an_attachment_points_at(): void
    {
        $attachment = RecordAttachment::factory()->create();

        $this->assertRejectedBy(
            'record_attachments_upload_id_foreign',
            fn () => Upload::query()->whereKey($attachment->upload_id)->delete(),
        );
    }

    public function test_rejects_a_record_of_another_module(): void
    {
        $record = Record::factory()->create();
        $otherModule = RecordModule::factory()->create();

        $this->assertRejectedBy(
            'record_attachments_record_module_id_record_id_foreign',
            fn () => RecordAttachment::factory()
                ->create(['record_module_id' => $otherModule->id, 'record_id' => $record->id]),
        );
    }
}
