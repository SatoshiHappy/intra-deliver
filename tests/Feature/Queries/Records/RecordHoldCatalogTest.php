<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Queries\Records\RecordHoldCatalog;
use Tests\Support\BuildsRecordModules;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 持ち主の実装を束ねる 1 か所(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の RecordHoldCatalog の行): 実装を登録の順に聞く。
 * blockingHold = 渡した鍵と違う最初の持ち主(鍵が null なら最初の持ち主)、firstHoldInModule = 最初の持ち主。実装が 0 件なら両方 null。
 */
final class RecordHoldCatalogTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RegistersRecordHolds;

    public function test_without_an_active_case_there_is_no_holder(): void
    {
        $module = $this->recordModule('projects');
        $record = Record::factory()->create(['record_module_id' => $module->id]);

        $this->assertNull($this->catalog()->blockingHold($record->id, null));
        $this->assertNull($this->catalog()->firstHoldInModule($module->id));
    }

    public function test_blocking_hold_is_the_first_holder_other_than_the_given_one_in_the_registration_order(): void
    {
        $module = $this->recordModule('projects');
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $this->registerRecordHolds([$record->id => 'b:1'], '2 番目');
        $this->registerRecordHolds([$record->id => 'a:1'], '1 番目');

        $this->assertSame('2 番目', $this->catalog()->blockingHold($record->id, null)?->reason);
        $this->assertSame('1 番目', $this->catalog()->blockingHold($record->id, 'b:1')?->reason);
        $this->assertSame('2 番目', $this->catalog()->blockingHold($record->id, 'a:1')?->reason);
        $this->assertNull($this->catalog()->blockingHold(Record::factory()->create(['record_module_id' => $module->id])->id, null));
    }

    public function test_the_given_holder_alone_does_not_block(): void
    {
        $module = $this->recordModule('projects');
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $this->registerRecordHolds([$record->id => 'a:1']);

        $this->assertNull($this->catalog()->blockingHold($record->id, 'a:1'));
        $this->assertSame('a:1', $this->catalog()->blockingHold($record->id, 'b:1')?->holder);
    }

    public function test_first_hold_in_module_asks_the_implementations_in_the_registration_order(): void
    {
        $module = $this->recordModule('projects');
        $other = $this->recordModule('others');
        $this->registerRecordHolds([Record::factory()->create(['record_module_id' => $other->id])->id => 'a:1'], 'ほかの業務モジュール');
        $this->registerRecordHolds([Record::factory()->create(['record_module_id' => $module->id])->id => 'b:1'], '2 番目');
        $this->registerRecordHolds([Record::factory()->create(['record_module_id' => $module->id])->id => 'c:1'], '3 番目');

        $this->assertSame('2 番目', $this->catalog()->firstHoldInModule($module->id)?->reason);
        $this->assertSame('ほかの業務モジュール', $this->catalog()->firstHoldInModule($other->id)?->reason);
    }

    private function catalog(): RecordHoldCatalog
    {
        return app(RecordHoldCatalog::class);
    }
}
