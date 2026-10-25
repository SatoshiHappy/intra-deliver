<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Queries\Records\Api\RecordRelations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 参照先を項目ごとの束にして返す読み取り(referencedByField)と、参照元の読み取り(referencingByField)が指す行の無いときに入口を読まないこと。
 *
 * @phpstan-import-type Bundle from RecordRelations
 */
final class RecordRelationsTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    private RecordModule $projects;

    private RecordModule $issues;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = $this->recordModule('projects');
        $this->issues = $this->recordModule('issues');
    }

    /**
     * 他モジュール参照の項目ごとに 1 つの束。module = 参照先の業務モジュール。並びは項目の位置(sort_key を作った順と逆にする)。
     */
    public function test_bundles_the_referenced_records_by_field(): void
    {
        $customers = $this->recordModule('customers');
        $project = $this->referenceField($this->issues, 'project', $this->projects, ['sort_key' => 3]);
        $parent = $this->referenceField($this->issues, 'parent_issue', $this->issues, ['sort_key' => 2]);
        $customer = $this->referenceField($this->issues, 'customer', $customers, ['sort_key' => 1]);
        $issue = $this->recordIn($this->issues);
        [$projectRecord, $parentIssue, $customerRecord] = [
            $this->recordIn($this->projects), $this->recordIn($this->issues), $this->recordIn($customers),
        ];
        $this->refer($issue, $project, $projectRecord);
        $this->refer($issue, $parent, $parentIssue);
        $this->refer($issue, $customer, $customerRecord);

        $this->assertSame([
            $this->bundle($customer, $customers, [$customerRecord->id]),
            $this->bundle($parent, $this->issues, [$parentIssue->id]),
            $this->bundle($project, $this->projects, [$projectRecord->id]),
        ], app(RecordRelations::class)->referencedByField($issue->id));
    }

    /**
     * 明細のモジュールの項目は全部の行の値を 1 つの束(昇順)にし、detailFieldName = 明細の項目の名前。明細のモジュールの項目は
     * 明細の項目の位置に、明細のモジュールの項目の並びで並ぶ。
     */
    public function test_a_field_of_a_detail_module_bundles_all_rows_at_the_details_field_position(): void
    {
        $late = $this->referenceField($this->issues, 'late', $this->projects, ['sort_key' => 3]);
        $tasks = $this->detailsField($this->issues, 'tasks', ['sort_key' => 2]);
        $taskLate = $this->referenceField($this->childOf($tasks), 'task_late', $this->projects, ['sort_key' => 2]);
        $taskEarly = $this->referenceField($this->childOf($tasks), 'task_early', $this->projects, ['sort_key' => 1]);
        $early = $this->referenceField($this->issues, 'early', $this->projects, ['sort_key' => 1]);
        [$low, $high] = [$this->recordIn($this->projects), $this->recordIn($this->projects)];
        $issue = $this->recordIn($this->issues);
        [$first, $second] = [$this->rowOf($issue, $taskEarly), $this->rowOf($issue, $taskEarly)];
        $this->refer($first, $taskEarly, $high);
        $this->refer($second, $taskEarly, $low);
        $this->refer($first, $taskLate, $low);
        $this->refer($issue, $late, $high);
        $this->refer($issue, $early, $low);

        $this->assertSame([
            $this->bundle($early, $this->projects, [$low->id]),
            $this->bundle($taskEarly, $this->projects, [$low->id, $high->id], 'tasks'),
            $this->bundle($taskLate, $this->projects, [$low->id], 'tasks'),
            $this->bundle($late, $this->projects, [$high->id]),
        ], app(RecordRelations::class)->referencedByField($issue->id));
    }

    /**
     * 同じ参照先を 2 つの行・2 つの項目から指すと、束ごとに 1 回。自分(明細の行が親を指す・自分の項目が自分を指す)を除き、
     * 自分だけを指す項目と値の無い項目は束を出さない。参照の無いレコードは空。
     */
    public function test_record_ids_are_unique_per_bundle_without_itself(): void
    {
        $main = $this->referenceField($this->issues, 'main_project', $this->projects);
        $sub = $this->referenceField($this->issues, 'sub_project', $this->projects);
        $self = $this->referenceField($this->issues, 'self', $this->issues);
        $this->referenceField($this->issues, 'unused', $this->projects);
        $tasks = $this->detailsField($this->issues, 'tasks');
        $taskProject = $this->referenceField($this->childOf($tasks), 'task_project', $this->projects);
        $taskIssue = $this->referenceField($this->childOf($tasks), 'task_issue', $this->issues);
        $project = $this->recordIn($this->projects);
        $issue = $this->recordIn($this->issues);
        $this->refer($issue, $main, $project);
        $this->refer($issue, $sub, $project);
        $this->refer($issue, $self, $issue);
        $this->refer($this->rowOf($issue, $taskProject), $taskProject, $project);
        $this->refer($this->rowOf($issue, $taskProject), $taskProject, $project);
        $this->refer($this->rowOf($issue, $taskIssue), $taskIssue, $issue);
        $relations = app(RecordRelations::class);

        $this->assertSame([
            $this->bundle($main, $this->projects, [$project->id]),
            $this->bundle($sub, $this->projects, [$project->id]),
            $this->bundle($taskProject, $this->projects, [$project->id], 'tasks'),
        ], $relations->referencedByField($issue->id));
        $this->assertSame([], $relations->referencedByField($project->id));
        $this->assertSame([], $relations->referencedByField($this->recordIn($this->issues)->id));
    }

    public function test_a_missing_record_and_a_details_row_are_not_found(): void
    {
        $tasks = $this->detailsField($this->issues, 'tasks');
        $row = $this->rowOf($this->recordIn($this->issues), $this->field($this->childOf($tasks), 'memo'));

        foreach ([999_999_999, $row->id] as $recordId) {
            $this->assertThrows(
                fn () => app(RecordRelations::class)->referencedByField($recordId),
                ModelNotFoundException::class,
            );
        }
    }

    /**
     * 項目 1 つ・明細の行 1 つ・参照先 1 つのときと、項目・明細の行・参照先が多いときで問い合わせの数が同じ。主体の判定の SQL が出ない。
     */
    public function test_the_number_of_queries_does_not_depend_on_fields_rows_and_targets(): void
    {
        $small = $this->recordModule('small');
        $smallField = $this->referenceField($small, 'project', $this->projects);
        $smallTasks = $this->childOf($this->detailsField($small, 'small_tasks'));
        $smallTask = $this->referenceField($smallTasks, 'task', $this->projects);
        $one = $this->recordIn($small);
        $this->refer($one, $smallField, $this->recordIn($this->projects));
        $this->refer($this->rowOf($one, $smallTask), $smallTask, $this->recordIn($this->projects));
        $tasks = $this->childOf($this->detailsField($this->issues, 'tasks'));
        $fields = array_map(fn (int $i) => $this->referenceField($this->issues, "f{$i}", $this->projects), [1, 2, 3]);
        $taskFields = array_map(fn (int $i) => $this->referenceField($tasks, "task_{$i}", $this->projects), [1, 2, 3]);
        $many = $this->recordIn($this->issues);

        foreach ($fields as $field) {
            $this->refer($many, $field, $this->recordIn($this->projects));
        }

        for ($i = 0; $i < 5; $i++) {
            foreach ($taskFields as $taskField) {
                $this->refer($this->rowOf($many, $taskField), $taskField, $this->recordIn($this->projects));
            }
        }

        $relations = app(RecordRelations::class);
        [$oneSql, $oneBundles] = $this->recordingQueries(fn () => $relations->referencedByField($one->id));
        [$manySql, $manyBundles] = $this->recordingQueries(fn () => $relations->referencedByField($many->id));
        $sizes = fn (array $bundles) => array_map(fn (array $bundle) => count($bundle['recordIds']), $bundles);

        $this->assertSame([[1, 1], [5, 5, 5, 1, 1, 1]], [$sizes($oneBundles), $sizes($manyBundles)]);
        $this->assertNotSame([], $oneSql);
        $this->assertSame(count($oneSql), count($manySql));
        $this->assertSame([], preg_grep('/authz_|created_by_user_id/', [...$oneSql, ...$manySql]));
    }

    /**
     * 参照の値の無いレコードの referencedByField は、入口(項目の定義)とレコードの業務モジュールを読む問い合わせを出さずに空を返す。
     */
    public function test_referenced_by_field_without_referring_rows_does_not_read_the_entries_or_the_module(): void
    {
        $this->referenceField($this->issues, 'project', $this->projects);
        $issue = $this->recordIn($this->issues);

        $relations = app(RecordRelations::class);

        [$sql, $bundles] = $this->recordingQueries(fn () => $relations->referencedByField($issue->id));

        $this->assertSame([], $bundles);
        $this->assertNotSame([], $sql);
        $this->assertSame([], preg_grep('/record_fields|record_modules/', $sql));
    }

    /**
     * 指す行が無いレコードの referencingByField は、入口(項目と業務モジュールの定義)を読む問い合わせを出さずに空を返す。
     */
    public function test_referencing_by_field_without_referring_rows_does_not_read_the_entries(): void
    {
        $this->referenceField($this->issues, 'project', $this->projects);
        $project = $this->recordIn($this->projects);

        $relations = app(RecordRelations::class);

        [$sql, $bundles] = $this->recordingQueries(fn () => $relations->referencingByField($project->id));

        $this->assertSame([], $bundles);
        $this->assertNotSame([], $sql);
        $this->assertSame([], preg_grep('/record_fields|record_modules/', $sql));
    }

    /**
     * 呼び出しの間に出た SQL(小文字)と、呼び出しの結果。
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return array{list<string>, T}
     */
    private function recordingQueries(callable $call): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $call();
            $sql = array_values(array_map(fn (array $query) => strtolower($query['query']), DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }

        return [$sql, $result];
    }

    /**
     * @param  non-empty-list<int>  $recordIds
     * @return Bundle
     */
    private function bundle(
        RecordField $field,
        RecordModule $module,
        array $recordIds,
        ?string $detailFieldName = null,
    ): array {
        return [
            'field' => ['id' => $field->id, 'name' => $field->name, 'detailFieldName' => $detailFieldName],
            'module' => ['id' => $module->id, 'name' => $module->name],
            'recordIds' => $recordIds,
        ];
    }

    private function recordIn(RecordModule $module): Record
    {
        return Record::factory()->create(['record_module_id' => $module->id]);
    }

    /**
     * 明細のモジュールの項目の、親のレコードの明細の行。
     */
    private function rowOf(Record $parent, RecordField $childField): Record
    {
        return Record::factory()->create([
            'record_module_id' => $childField->record_module_id,
            'parent_record_module_id' => $parent->record_module_id,
            'parent_record_id' => $parent->id,
        ]);
    }

    private function refer(Record $record, RecordField $field, Record $referenced): void
    {
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => null,
            'referenced_record_module_id' => $referenced->record_module_id,
            'referenced_record_id' => $referenced->id,
        ]);
    }
}
