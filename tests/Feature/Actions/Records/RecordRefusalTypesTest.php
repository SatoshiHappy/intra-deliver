<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\DeleteRecordUnchecked;
use App\Actions\Records\Api\RecordHolderRefusal;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\RecordStaleRefusal;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\Api\UpdateRecordUnchecked;
use App\Actions\Records\DeleteRecord;
use App\Actions\Records\DetachRecordAttachments;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use Closure;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 書き込みの断りの型(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md の 9.2 の 2): 持ち主の断りは書き込みのどの入口でも
 * 同じ型、開いた後の更新の断りは別の型で、どちらも ValidationException(キー record)のまま。ほかの検証の失敗は元の型。
 */
final class RecordRefusalTypesTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RegistersRecordHolds;

    private RecordModule $module;

    private Record $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = $this->recordModule('refusal_check');
        $this->field($this->module, 'title');
        $this->record = Record::factory()->create(['record_module_id' => $this->module->id]);
    }

    public function test_an_update_after_another_update_is_a_stale_refusal(): void
    {
        $opened = $this->record->version();
        $this->travel(1)->seconds();
        $this->update($opened);

        $refusal = $this->refusalOf(fn () => $this->update($opened));

        $this->assertInstanceOf(RecordStaleRefusal::class, $refusal);
        $this->assertSame(['record'], array_keys($refusal->errors()));
    }

    public function test_writes_on_a_held_record_are_holder_refusals(): void
    {
        $this->registerRecordHolds([$this->record->id => 'test-holder:1']);
        $id = $this->record->id;
        $ai = new RecordRevisionOrigin('AI');
        $writes = [
            'update' => fn () => $this->update($this->record->version()),
            'attach' => fn () => app(AttachRecordUploads::class)
                ->execute($this->contextFor(), 'refusal_check', $id, []),
            'update without judging' => fn () => app(UpdateRecordUnchecked::class)
                ->execute($this->contextFor(), 'refusal_check', $id, new RecordInput([]), $ai),
            'detach' => fn () => app(DetachRecordAttachments::class)
                ->execute($this->contextFor(), 'refusal_check', $id, $this->attachmentId()),
            'delete' => fn () => app(DeleteRecord::class)->execute($this->contextFor(), 'refusal_check', $id),
            'delete without judging' => fn () => app(DeleteRecordUnchecked::class)->execute($id),
        ];

        foreach ($writes as $name => $write) {
            $refusal = $this->refusalOf($write);

            $this->assertInstanceOf(RecordHolderRefusal::class, $refusal, $name);
            $this->assertSame(['record' => [self::HOLD_REASON]], $refusal->errors(), $name);
        }
    }

    public function test_other_validation_failures_keep_the_plain_type(): void
    {
        $refusal = $this->refusalOf(fn () => app(UpdateRecord::class)->execute(
            $this->contextFor(),
            'refusal_check',
            $this->record->id,
            new RecordInput(['title' => str_repeat('長', 10000)]),
            $this->record->version(),
        ));

        $this->assertSame(ValidationException::class, $refusal::class);
    }

    private function update(string $version): void
    {
        app(UpdateRecord::class)
            ->execute($this->contextFor(), 'refusal_check', $this->record->id, new RecordInput([]), $version);
    }

    private function attachmentId(): int
    {
        return RecordAttachment::factory()
            ->create(['record_module_id' => $this->module->id, 'record_id' => $this->record->id])->id;
    }

    private function refusalOf(Closure $write): ValidationException
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            return $exception;
        }

        $this->fail('断られなかった');
    }
}
