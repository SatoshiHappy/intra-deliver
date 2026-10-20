<?php

namespace Tests\Feature\Models\Records;

use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュール(docs の 設計/業務データの入れ物/データモデル/ 1 節・2.1・7 節: コードは英小文字で始まる英小文字・数字・_ の 50 文字まで、
 * 名前は空にしない、自分を親にしない、親の無い業務モジュールだけがアイコンを持つ)と、
 * 明細のモジュールが添付を使用しないこと(設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.8 節)。
 */
final class RecordModuleTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCodes(): array
    {
        return [
            '大文字' => ['Projects'],
            '先頭の数字' => ['1projects'],
            '先頭の _' => ['_projects'],
            '- を含む' => ['project-tasks'],
            '51 文字' => [str_repeat('a', 51)],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wellFormedCodes(): array
    {
        return [
            '1 文字' => ['a'],
            '数字と _ を含む' => ['project_2026'],
            '50 文字' => [str_repeat('a', 50)],
        ];
    }

    #[DataProvider('malformedCodes')]
    public function test_rejects_a_malformed_code(string $code): void
    {
        $this->assertRejectedBy('record_modules_code_check', fn () => RecordModule::factory()->create(['code' => $code]));
    }

    #[DataProvider('wellFormedCodes')]
    public function test_accepts_a_well_formed_code(string $code): void
    {
        $module = RecordModule::factory()->create(['code' => $code]);

        $this->assertSame($code, $module->refresh()->code);
    }

    public function test_rejects_an_empty_name(): void
    {
        $this->assertRejectedBy('record_modules_name_check', fn () => RecordModule::factory()->create(['name' => '']));
    }

    public function test_rejects_a_module_as_its_own_parent(): void
    {
        $module = RecordModule::factory()->create();

        // 親を付けるのでアイコンを外す(アイコンの検査制約に先に当たらないように)
        $this->assertRejectedBy('record_modules_parent_record_module_id_id_check', fn () => $module->update(['parent_record_module_id' => $module->id, 'icon' => null]));
    }

    public function test_rejects_a_module_without_a_parent_and_without_an_icon(): void
    {
        $this->assertRejectedBy('record_modules_parent_record_module_id_icon_check', fn () => RecordModule::factory()->create(['icon' => null]));
    }

    public function test_rejects_a_details_module_with_an_icon(): void
    {
        $this->assertRejectedBy('record_modules_parent_record_module_id_icon_check', fn () => RecordModule::factory()->detail()->create(['icon' => 'table']));
    }

    public function test_the_factory_gives_an_icon_only_to_a_module_without_a_parent(): void
    {
        $child = RecordModule::factory()->detail()->create();

        $this->assertSame(['table', null], [$child->parentRecordModule?->icon, $child->icon]);
    }

    public function test_rejects_a_details_module_that_uses_attachments(): void
    {
        $this->assertRejectedBy(
            'record_modules_parent_record_module_id_is_attachment_enabled_check',
            fn () => RecordModule::factory()->detail()->create(['is_attachment_enabled' => true]),
        );
    }

    public function test_the_factory_enables_attachments_only_for_a_module_without_a_parent(): void
    {
        $module = RecordModule::factory()->create();
        $child = RecordModule::factory()->detail()->create();
        $fieldChild = RecordField::factory()->details()->create()->childRecordModule;

        $this->assertTrue($module->refresh()->is_attachment_enabled);
        $this->assertFalse($child->refresh()->is_attachment_enabled);
        $this->assertFalse($fieldChild?->is_attachment_enabled);
    }

    public function test_accepts_a_module_without_a_parent_that_does_not_use_attachments(): void
    {
        $module = RecordModule::factory()->create(['is_attachment_enabled' => false]);

        $this->assertFalse($module->refresh()->is_attachment_enabled);
    }
}
