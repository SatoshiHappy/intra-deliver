<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\RecordAttachmentGate;
use App\Actions\Records\UpdateRecordModule;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールの「添付ファイルを使用」(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 2 節・
 * 7 節の「添付ファイルの使用」): 値を書くだけ。外すと添付の使用の判定が偽になり、付いている添付は残る。
 * 明細のモジュールに送ると 422 で、真偽でない値も 422。送らなければ今の値のまま。
 */
final class UpdateRecordModuleAttachmentTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    public function test_turning_it_off_fails_the_use_check_and_keeps_the_attachments(): void
    {
        $module = $this->recordModule('projects');
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $attachment = RecordAttachment::factory()->create([
            'record_module_id' => $module->id,
            'record_id' => $record->id,
        ]);
        $gate = app(RecordAttachmentGate::class);

        $this->update('projects', ['is_attachment_enabled' => false]);

        $this->assertFalse($module->refresh()->is_attachment_enabled);
        $errors = $this->validationErrors(fn () => $gate->ensureEnabled($module));
        $this->assertSame(['attachments' => RecordAttachmentGate::DISABLED], $errors);
        $this->assertTrue(RecordAttachment::query()->whereKey($attachment->id)->exists());

        $this->update('projects', ['is_attachment_enabled' => true]);

        $gate->ensureEnabled($module->refresh());
        $this->assertTrue(RecordAttachment::query()->whereKey($attachment->id)->exists());
    }

    public function test_leaving_it_out_or_sending_null_keeps_the_current_value(): void
    {
        $module = $this->recordModule('projects');
        $this->update('projects', ['is_attachment_enabled' => false]);

        $this->update('projects', ['description' => '説明']);
        $this->update('projects', ['is_attachment_enabled' => null]);

        $this->assertFalse($module->refresh()->is_attachment_enabled);
    }

    public function test_a_details_module_cannot_be_given_it_and_nothing_is_written(): void
    {
        $child = $this->childOf($this->detailsField($this->recordModule('projects'), 'tasks'));

        $errors = $this->validationErrors(
            fn () => $this->update($child->code, ['name' => '作業', 'is_attachment_enabled' => true]),
        );

        $this->assertSame(['is_attachment_enabled' => '明細には設定できません'], $errors);
        $this->assertSame($child->name, RecordModule::query()->findOrFail($child->id)->name);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notBooleans(): array
    {
        return ['文字列' => ['false'], '数' => [0], '配列' => [[true]]];
    }

    #[DataProvider('notBooleans')]
    public function test_rejects_a_value_that_is_not_a_boolean(mixed $value): void
    {
        $module = $this->recordModule('projects');

        $errors = $this->validationErrors(fn () => $this->update('projects', ['is_attachment_enabled' => $value]));

        $this->assertSame(['is_attachment_enabled' => '真偽(true・false)で指定してください'], $errors);
        $this->assertTrue($module->refresh()->is_attachment_enabled);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function update(string $moduleCode, array $input): void
    {
        app(UpdateRecordModule::class)->execute($moduleCode, $input);
    }
}
