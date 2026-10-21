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

    /**
     * まとめて読む口も同じ値を返し(重複は 1 つ)、明細のモジュール・無い業務モジュールが混ざれば呼び手の誤り。
     */
    public function test_the_batch_returns_the_same_values_and_rejects_detail_and_missing_modules(): void
    {
        $enabled = RecordModule::factory()->create();
        $disabled = RecordModule::factory()->create(['is_attachment_enabled' => false]);
        $settings = app(RecordModuleSettings::class);

        $values = $settings->attachmentEnabledByIds([$enabled->id, $disabled->id, $enabled->id]);
        ksort($values);

        $this->assertSame([$enabled->id => true, $disabled->id => false], $values);
        $this->assertSame([], $settings->attachmentEnabledByIds([]));

        $detailId = RecordModule::factory()->detail()->create()->id;

        foreach ([$detailId, (int) RecordModule::query()->max('id') + 1] as $wrongId) {
            $this->assertThrows(
                fn () => $settings->attachmentEnabledByIds([$enabled->id, $wrongId]),
                ModelNotFoundException::class,
            );
        }
    }
}
