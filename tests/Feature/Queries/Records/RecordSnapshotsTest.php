<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Records\RecordFieldType;
use App\Models\Master\DepartmentSet;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordSnapshots;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の版の写し(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.1 節): 保存形の values・references・details と
 * 今の添付 attachments。
 */
final class RecordSnapshotsTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    public function test_a_snapshot_holds_stored_values_reference_targets_detail_rows_and_attachments(): void
    {
        $customers = $this->recordModule('customers');
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title');
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $section = $this->field($module, 'section', RecordFieldType::Department);
        $customer = $this->referenceField($module, 'customer', $customers);
        $tasks = $this->detailsField($module, 'tasks');
        $taskName = $this->field($this->childOf($tasks), 'task_name');
        $taskOwner = $this->field($this->childOf($tasks), 'task_owner', RecordFieldType::User);
        $notes = $this->detailsField($module, 'notes');
        $user = $this->user();
        $department = $this->department(DepartmentSet::factory()->create());
        $customerRecord = Record::factory()->create(['record_module_id' => $customers->id]);
        $values = [$title->id => '社内ポータル', 999_999 => '消した項目の値'];
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => $values]);
        $this->refer($record, $owner->id, ['user_id' => $user->id]);
        $this->refer($record, $section->id, ['user_id' => null, 'department_id' => $department->id]);
        $this->refer($record, $customer->id, [
            'user_id' => null,
            'referenced_record_module_id' => $customers->id,
            'referenced_record_id' => $customerRecord->id,
        ]);
        $second = $this->row($tasks->child_record_module_id, $record, 1, [$taskName->id => '実装']);
        $first = $this->row($tasks->child_record_module_id, $record, 0, [$taskName->id => '設計']);
        $this->refer($second, $taskOwner->id, ['user_id' => $user->id]);
        $estimate = $this->attach($record, '見積書.pdf');
        $minutes = $this->attach($record, '議事録.pdf');

        $this->assertSame([
            'values' => [$title->id => '社内ポータル', 999_999 => '消した項目の値'],
            'references' => [
                $owner->id => ['type' => 'user', 'id' => $user->id],
                $section->id => ['type' => 'department', 'id' => $department->id],
                $customer->id => ['type' => 'record', 'id' => $customerRecord->id],
            ],
            'details' => [
                $tasks->id => [
                    ['id' => $first->id, 'values' => [$taskName->id => '設計'], 'references' => []],
                    ['id' => $second->id, 'values' => [$taskName->id => '実装'], 'references' => [
                        $taskOwner->id => ['type' => 'user', 'id' => $user->id],
                    ]],
                ],
                $notes->id => [],
            ],
            'attachments' => [$estimate, $minutes],
            'row_access' => ['restricted' => false, 'viewers' => []],
        ], $this->snapshot($record));
    }

    public function test_the_details_keys_follow_the_order_of_the_definition(): void
    {
        $module = $this->recordModule('projects');
        $later = $this->detailsField($module, 'later', ['sort_key' => 20]);
        $earlier = $this->detailsField($module, 'earlier', ['sort_key' => 10]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);

        $this->assertSame([$earlier->id, $later->id], array_keys($this->snapshot($record)['details']));
    }

    public function test_a_snapshot_reads_what_the_caller_wrote_in_its_transaction(): void
    {
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title');
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '旧']]);

        $snapshot = DB::transaction(function () use ($record): array {
            $input = new RecordInput(['title' => '新']);
            app(UpdateRecord::class)->execute($this->contextFor(), 'projects', $record->id, $input, $record->version());

            return $this->snapshot($record->refresh());
        });

        $this->assertSame([$title->id => '新'], $snapshot['values']);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Record $record): array
    {
        $module = app(RecordDefinitionQuery::class)->load(RecordModule::query()->findOrFail($record->record_module_id));

        return app(RecordSnapshots::class)->snapshot($record, $module);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function row(?int $childModuleId, Record $parent, int $sortKey, array $values): Record
    {
        return Record::factory()->create([
            'record_module_id' => $childModuleId, 'parent_record_module_id' => $parent->record_module_id,
            'parent_record_id' => $parent->id, 'sort_key' => $sortKey, 'field_values' => $values,
        ]);
    }

    /**
     * @param  array<string, int|null>  $target
     */
    private function refer(Record $record, int $fieldId, array $target): void
    {
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $fieldId,
            ...$target,
        ]);
    }

    /**
     * @return array{id: int, upload_id: int, name: string, size: int, mime: string}
     */
    private function attach(Record $record, string $name): array
    {
        $attachment = RecordAttachment::factory()->create([
            'record_module_id' => $record->record_module_id, 'record_id' => $record->id, 'name' => $name,
        ]);

        return $this->attachmentOf($attachment);
    }

    /**
     * @return array{id: int, upload_id: int, name: string, size: int, mime: string}
     */
    private function attachmentOf(RecordAttachment $attachment): array
    {
        $upload = $attachment->upload()->sole();

        return [
            'id' => $attachment->id, 'upload_id' => $upload->id, 'name' => $attachment->name,
            'size' => $upload->size, 'mime' => $upload->mime,
        ];
    }
}
