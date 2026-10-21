<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Queries\Records\Api\RecordHoldQuery;
use Tests\Support\BuildsRecordModules;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 後ろのモジュールが持ち主の有無を読む口(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節): 束ねる 1 か所に委ね、どの実装の持ち主でもあれば真。
 */
final class RecordHoldQueryTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RegistersRecordHolds;

    public function test_a_record_is_held_when_any_implementation_holds_it(): void
    {
        $module = $this->recordModule('projects');
        $held = Record::factory()->create(['record_module_id' => $module->id]);
        $free = Record::factory()->create(['record_module_id' => $module->id]);
        $this->registerRecordHolds([]);
        $this->registerRecordHolds([$held->id => 'workflow-case:1']);

        $this->assertTrue(app(RecordHoldQuery::class)->isHeld($held->id));
        $this->assertFalse(app(RecordHoldQuery::class)->isHeld($free->id));
    }
}
