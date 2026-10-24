<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\DeleteRecordField;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローのコンテンツが申請対象に使っている項目の削除(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md
 * 11 節の「項目を消せるか」の表の 4 行目): 消す文の外部キー違反が 422 field(ほかの表から指されている項目の文言)になり、項目も
 * コンテンツの申請対象も残る。
 */
final class DeleteRecordFieldApplicationTargetTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    public function test_a_field_used_as_the_application_target_of_a_content_cannot_be_deleted(): void
    {
        $content = WorkflowContent::factory()->withTarget()->createOne();
        $field = RecordField::query()->findOrFail($content->target_record_field_id);
        $moduleCode = RecordModule::query()->findOrFail($field->record_module_id)->code;

        $errors = $this->validationErrors(fn () => app(DeleteRecordField::class)->execute($moduleCode, $field->code));

        $this->assertSame(['field' => "{$field->name}はほかのデータから参照されているため削除できません"], $errors);
        $this->assertModelExists($field);
        $this->assertSame(
            [$field->id, $field->referenced_record_module_id],
            [$content->refresh()->target_record_field_id, $content->target_record_module_id],
        );
    }
}
