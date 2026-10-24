<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Queries\Records\Api\RecordRelations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの関係の口(relatedRecordIds): 参照先・参照元(明細の行から指すときは親)を返し、自分を除く。
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

    /**
     * 2 つの項目が同じレコードを指しても、その id は 1 つだけ入る(参照先・参照元のどちらの向きでも)。
     */
    public function test_a_record_referred_to_by_two_fields_is_listed_once(): void
    {
        $projects = $this->recordModule('projects');
        $issues = $this->recordModule('issues');
        $mainProject = $this->referenceField($issues, 'main_project', $projects);
        $subProject = $this->referenceField($issues, 'sub_project', $projects);
        $project = Record::factory()->create(['record_module_id' => $projects->id]);
        $issue = Record::factory()->create(['record_module_id' => $issues->id]);
        $this->refer($issue, $mainProject->id, $projects, $project);
        $this->refer($issue, $subProject->id, $projects, $project);

        $this->assertSame(
            ['referenced' => [$project->id], 'referencing' => []],
            app(RecordRelations::class)->relatedRecordIds($issue->id),
        );
        $this->assertSame(
            ['referenced' => [], 'referencing' => [$issue->id]],
            app(RecordRelations::class)->relatedRecordIds($project->id),
        );
    }

    /**
     * relatedRecordIds の referencing は、referencingByField の全部の束の recordIds を合わせて重複を除き昇順にしたものと一致する
     * (明細の行から指す・2 つの項目で同じレコードを指す・自分を指す)。
     */
    public function test_referencing_ids_match_the_union_of_the_bundles_by_field(): void
    {
        $projects = $this->recordModule('projects');
        $issues = $this->recordModule('issues');
        $first = $this->referenceField($issues, 'first', $projects);
        $second = $this->referenceField($issues, 'second', $projects);
        $self = $this->referenceField($projects, 'self', $projects);
        $tasks = $this->detailsField($issues, 'tasks');
        $taskProject = $this->referenceField($this->childOf($tasks), 'task_project', $projects);
        $project = Record::factory()->create(['record_module_id' => $projects->id]);
        $low = Record::factory()->create(['record_module_id' => $issues->id]);
        $high = Record::factory()->create(['record_module_id' => $issues->id]);
        $this->refer($high, $first->id, $projects, $project);
        $this->refer($high, $second->id, $projects, $project);
        $this->refer($this->row($tasks->child_record_module_id, $low), $taskProject->id, $projects, $project);
        $this->refer($project, $self->id, $projects, $project);
        $relations = app(RecordRelations::class);
        $bundledIds = array_merge(...array_column($relations->referencingByField($project->id), 'recordIds'));
        $bundledIds = array_values(array_unique($bundledIds));
        sort($bundledIds);

        $this->assertSame([$low->id, $high->id], $bundledIds);
        $this->assertSame($bundledIds, $relations->relatedRecordIds($project->id)['referencing']);
    }

    /**
     * 指す行が無いレコードの referencingByField は、入口(項目と業務モジュールの定義)を読む問い合わせを出さずに空を返す。
     */
    public function test_referencing_by_field_without_referring_rows_does_not_read_the_entries(): void
    {
        $projects = $this->recordModule('projects');
        $this->referenceField($this->recordModule('issues'), 'project', $projects);
        $project = Record::factory()->create(['record_module_id' => $projects->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $bundles = app(RecordRelations::class)->referencingByField($project->id);
            $sql = array_map(fn (array $query) => strtolower($query['query']), DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame([], $bundles);
        $this->assertNotSame([], $sql);
        $this->assertSame([], preg_grep('/record_fields|record_modules/', $sql));
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
