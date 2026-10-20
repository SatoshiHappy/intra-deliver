<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\CreateRecordModule;
use App\Models\Records\RecordModule;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 型「明細」の項目を足すと、明細のモジュールが添付を使用しない(is_attachment_enabled = false)で作られる(docs の
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.8 節。列の既定は true で、書かないと検査制約で落ちる)。
 * 業務モジュールを作る業務処理は書かず、既定の true で作られる。
 */
final class AddRecordFieldAttachmentTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_a_details_module_made_by_adding_a_details_field_does_not_use_attachments(): void
    {
        $module = $this->recordModule('projects');

        $field = app(AddRecordField::class)->execute('projects', [
            'code' => 'tasks',
            'name' => 'タスク',
            'type' => 'details',
            'child_module' => ['code' => 'project_tasks', 'name' => 'プロジェクトのタスク'],
        ]);

        $child = RecordModule::query()->where('code', 'project_tasks')->sole();
        $this->assertSame($child->id, $field->child_record_module_id);
        $this->assertSame($module->id, $child->parent_record_module_id);
        $this->assertFalse($child->is_attachment_enabled);
        $this->assertTrue($module->refresh()->is_attachment_enabled);
    }

    public function test_a_module_made_by_the_create_module_action_uses_attachments(): void
    {
        $module = app(CreateRecordModule::class)->execute(['code' => 'equipment', 'name' => '備品']);

        $this->assertTrue($module->refresh()->is_attachment_enabled);
    }
}
