<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Queries\Records\Api\RecordRelations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの関係の口(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節・9 節「口」、公開の口.md 7 節の表):
 * 参照先・参照元(明細の行から指すときは親)を返し、自分を除く。
 */
final class RecordRelationsTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_returns_referenced_and_referencing_top_level_records_without_itself(): void
    {
        $projects = $this->recordModule('projects');
        $issues = $this->recordModule('issues');
        $projectOfIssue = $this->referenceField($issues, 'project', $projects);
        $parentIssue = $this->referenceField($issues, 'parent_issue', $issues);
        $tasks = $this->detailsField($issues, 'tasks');
        $taskProject = $this->referenceField($this->childOf($tasks), 'task_project', $projects);
        $relatedIssue = $this->referenceField($this->childOf($tasks), 'related_issue', $issues);
        $projectA = Record::factory()->create(['record_module_id' => $projects->id]);
        $projectB = Record::factory()->create(['record_module_id' => $projects->id]);
        $issue = Record::factory()->create(['record_module_id' => $issues->id]);
        $child = Record::factory()->create(['record_module_id' => $issues->id]);
        $citing = Record::factory()->create(['record_module_id' => $issues->id]);
        $unrelated = Record::factory()->create(['record_module_id' => $issues->id]);
        $row = $this->row($tasks->child_record_module_id, $issue);
        $citingRow = $this->row($tasks->child_record_module_id, $citing);
        $this->refer($issue, $projectOfIssue->id, $projects, $projectA);
        $this->refer($row, $taskProject->id, $projects, $projectB);
        $this->refer($row, $relatedIssue->id, $issues, $issue);
        $this->refer($child, $parentIssue->id, $issues, $issue);
        $this->refer($citingRow, $relatedIssue->id, $issues, $issue);
        $this->refer($unrelated, $projectOfIssue->id, $projects, $projectA);

        $this->assertSame(
            ['referenced' => [$projectA->id, $projectB->id], 'referencing' => [$child->id, $citing->id]],
            app(RecordRelations::class)->relatedRecordIds($issue->id),
        );
        $this->assertSame(
            ['referenced' => [], 'referencing' => [$issue->id, $unrelated->id]],
            app(RecordRelations::class)->relatedRecordIds($projectA->id),
        );
    }

    public function test_a_record_without_references_has_no_relations(): void
    {
        $record = Record::factory()->create(['record_module_id' => $this->recordModule('projects')->id]);

        $this->assertSame(['referenced' => [], 'referencing' => []], app(RecordRelations::class)->relatedRecordIds($record->id));
    }

    public function test_a_missing_record_and_a_details_row_are_not_found(): void
    {
        $issues = $this->recordModule('issues');
        $tasks = $this->detailsField($issues, 'tasks');
        $row = $this->row($tasks->child_record_module_id, Record::factory()->create(['record_module_id' => $issues->id]));

        foreach ([999_999, $row->id] as $recordId) {
            try {
                app(RecordRelations::class)->relatedRecordIds($recordId);
                $this->fail('見つかった');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function row(?int $childModuleId, Record $parent): Record
    {
        return Record::factory()->create(['record_module_id' => $childModuleId, 'parent_record_module_id' => $parent->record_module_id, 'parent_record_id' => $parent->id]);
    }

    private function refer(Record $record, int $fieldId, RecordModule $referencedModule, Record $referenced): void
    {
        RecordReference::factory()->create(['record_module_id' => $record->record_module_id, 'record_id' => $record->id, 'record_field_id' => $fieldId, 'user_id' => null, 'referenced_record_module_id' => $referencedModule->id, 'referenced_record_id' => $referenced->id]);
    }
}
