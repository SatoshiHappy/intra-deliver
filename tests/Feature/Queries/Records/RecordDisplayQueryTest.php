<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの表示の口(docs の 設計/ワークフロー/業務データとの接続/README.md 1 節・9 節「口」、公開の口.md 7 節の表): 業務モジュールの read を
 * 見ずに値を返し、参照先の read が無い他モジュール参照の表示名は null。
 */
final class RecordDisplayQueryTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_shows_the_definition_and_values_without_read_on_the_module(): void
    {
        $module = $this->recordModule('projects', [RecordModuleAction::Create]);
        $readable = $this->recordModule('customers');
        $secrets = $this->recordModule('secrets', [RecordModuleAction::Create]);
        $title = $this->field($module, 'title', attributes: ['is_title' => true]);
        $customer = $this->referenceField($module, 'customer', $readable);
        $secret = $this->referenceField($module, 'secret', $secrets);
        $tasks = $this->detailsField($module, 'tasks');
        $taskName = $this->field($this->childOf($tasks), 'task_name');
        $customerRecord = Record::factory()->create(['record_module_id' => $readable->id, 'field_values' => [$this->field($readable, 'customer_name', attributes: ['is_title' => true])->id => '株式会社A']]);
        $secretRecord = Record::factory()->create(['record_module_id' => $secrets->id, 'field_values' => [$this->field($secrets, 'secret_name', attributes: ['is_title' => true])->id => '秘密']]);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '社内ポータル']]);
        $this->refer($record, $customer->id, $readable->id, $customerRecord->id);
        $this->refer($record, $secret->id, $secrets->id, $secretRecord->id);
        $row = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id, 'field_values' => [$taskName->id => '設計']]);

        $shown = $this->show($record->id);

        $this->assertSame('projects', $shown['module']['code']);
        $this->assertSame(['title', 'customer', 'secret', 'tasks'], array_column($shown['module']['fields'], 'code'));
        $this->assertSame($record->id, $shown['record']['id']);
        $this->assertSame([
            'title' => '社内ポータル',
            'customer' => ['id' => $customerRecord->id, 'code' => null, 'label' => '株式会社A', 'affiliation' => null],
            'secret' => ['id' => $secretRecord->id, 'code' => null, 'label' => null, 'affiliation' => null],
        ], $shown['record']['values']);
        $this->assertSame(['tasks' => [['id' => $row->id, 'values' => ['task_name' => '設計']]]], $shown['record']['details']);
        $this->assertSame(['createdAt', 'updatedAt', 'createdBy', 'updatedBy'], array_slice(array_keys($shown['record']), 3));
    }

    public function test_the_title_is_the_value_of_the_title_field_or_null(): void
    {
        $module = $this->recordModule('projects', [RecordModuleAction::Create]);
        $title = $this->field($module, 'title', attributes: ['is_title' => true]);
        $untitled = $this->recordModule('untitled');
        $named = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '社内ポータル']]);
        $blank = Record::factory()->create(['record_module_id' => $module->id]);
        $withoutTitleField = Record::factory()->create(['record_module_id' => $untitled->id, 'field_values' => [$this->field($untitled, 'memo')->id => 'メモ']]);

        $query = app(RecordDisplayQuery::class);
        $this->assertSame(['社内ポータル', null, null], [$query->titleOf($named->id), $query->titleOf($blank->id), $query->titleOf($withoutTitleField->id)]);
    }

    public function test_a_missing_record_and_a_details_row_are_not_found(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $parent = Record::factory()->create(['record_module_id' => $module->id]);
        $row = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $parent->id]);

        foreach ([999_999, $row->id] as $recordId) {
            foreach ([fn () => $this->show($recordId), fn () => app(RecordDisplayQuery::class)->titleOf($recordId)] as $call) {
                try {
                    $call();
                    $this->fail('見つかった');
                } catch (ModelNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_the_top_level_module_code_is_the_module_of_the_record_or_of_its_parent_or_null(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $row = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id]);

        $query = app(RecordDisplayQuery::class);
        $this->assertSame(['projects', 'projects', null], [$query->topLevelModuleCodeOf($record->id), $query->topLevelModuleCodeOf($row->id), $query->topLevelModuleCodeOf(999_999)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(int $recordId): array
    {
        return app(RecordDisplayQuery::class)->show(new Requester(null, null), $recordId, CarbonImmutable::parse(self::TODAY), 'ja');
    }

    private function refer(Record $record, int $fieldId, int $referencedModuleId, int $referencedRecordId): void
    {
        RecordReference::factory()->create(['record_module_id' => $record->record_module_id, 'record_id' => $record->id, 'record_field_id' => $fieldId, 'user_id' => null, 'referenced_record_module_id' => $referencedModuleId, 'referenced_record_id' => $referencedRecordId]);
    }
}
