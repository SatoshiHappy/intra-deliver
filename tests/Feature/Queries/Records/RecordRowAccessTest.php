<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordRowAccess;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 行の判定の 1 か所(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.4)に渡す業務モジュールの確かめ: 見える範囲か親の列を読んでいない
 * 業務モジュールと明細のモジュールは、絞らない側に倒さず作りの誤りとして止める。
 */
final class RecordRowAccessTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_a_module_read_without_the_row_access_scope_column_is_refused(): void
    {
        $module = RecordModule::query()->select('id')->whereKey($this->recordModule('meetings')->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $this->rowAccess()->constrain(Record::query(), new Requester(null, null), $module, $this->today());
    }

    public function test_a_created_module_without_the_default_row_access_scope_is_refused(): void
    {
        $module = RecordModule::query()->create([
            'code' => 'meetings',
            'name' => '会議',
            'icon' => 'table',
            'parent_record_module_id' => null,
            'sort_key' => 0,
        ]);

        $this->expectException(LogicException::class);
        $this->rowAccess()->allows(new Requester(null, null), $module, 1, $this->today());
    }

    public function test_a_detail_module_is_refused(): void
    {
        $details = $this->detailsField($this->recordModule('meetings'), 'agenda');
        $child = RecordModule::query()->findOrFail($details->child_record_module_id);

        $this->expectException(LogicException::class);
        $this->rowAccess()->constrain(Record::query(), new Requester(null, null), $child, $this->today());
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-11');
    }

    private function rowAccess(): RecordRowAccess
    {
        return app(RecordRowAccess::class);
    }
}
