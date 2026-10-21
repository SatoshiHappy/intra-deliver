<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordModuleSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールの設定を読む口(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 1.1 節): 「添付ファイルを使用」の今の値を返すだけで、
 * 明細のモジュール・無い業務モジュールは呼び手の誤り(404 の例外)。
 */
final class RecordModuleSettingsTest extends TransactionalTestCase
{
    public function test_it_returns_the_current_value_of_the_attachment_setting(): void
    {
        $enabled = RecordModule::factory()->create();
        $disabled = RecordModule::factory()->create(['is_attachment_enabled' => false]);
        $settings = app(RecordModuleSettings::class);

        $this->assertTrue($settings->isAttachmentEnabled($enabled->id));
        $this->assertFalse($settings->isAttachmentEnabled($disabled->id));

        $enabled->update(['is_attachment_enabled' => false]);

        $this->assertFalse($settings->isAttachmentEnabled($enabled->id));
    }

    public function test_a_detail_module_is_not_found(): void
    {
        $detail = RecordModule::factory()->detail()->create();

        $this->expectException(ModelNotFoundException::class);

        app(RecordModuleSettings::class)->isAttachmentEnabled($detail->id);
    }

    public function test_a_missing_module_is_not_found(): void
    {
        $missingId = (int) RecordModule::query()->max('id') + 1;

        $this->expectException(ModelNotFoundException::class);

        app(RecordModuleSettings::class)->isAttachmentEnabled($missingId);
    }
}
