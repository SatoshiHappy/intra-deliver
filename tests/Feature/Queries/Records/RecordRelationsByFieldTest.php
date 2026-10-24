<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
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
 * 参照元を項目ごとの束にして返す読み取り(referencingByField)。
 *
 * @phpstan-import-type Bundle from RecordRelations
 */
final class RecordRelationsByFieldTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    private RecordModule $projects;

    private RecordModule $applications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projects = $this->recordModule('projects');
        $this->applications = $this->recordModule('applications');
    }

    /**
     * 2 つの業務モジュールの 3 つの項目が同じレコードを指すと、項目ごとに 1 つの束。
     */
    public function test_bundles_the_referring_records_by_field(): void
    {
        $issues = $this->recordModule('issues');
        $first = $this->referenceField($this->applications, 'first_project', $this->projects);
        $second = $this->referenceField($this->applications, 'second_project', $this->projects);
        $issueProject = $this->referenceField($issues, 'issue_project', $this->projects);
        $project = $this->recordIn($this->projects);
        $application = $this->recordIn($this->applications);
        $issue = $this->recordIn($issues);
        $this->refer($application, $first, $project);
        $this->refer($application, $second, $project);
        $this->refer($issue, $issueProject, $project);

        $this->assertSame([
            $this->bundle($first, $this->applications, [$application->id]),
            $this->bundle($second, $this->applications, [$application->id]),
            $this->bundle($issueProject, $issues, [$issue->id]),
        ], app(RecordRelations::class)->referencingByField($project->id));
    }

    /**
     * 明細のモジュールの項目から指すと、業務モジュールは親、detailFieldName は親の明細の項目の名前、レコードは親(同じ親の 2 行は 1 つ)。
     */
    public function test_a_field_of_a_detail_module_is_bundled_under_the_parent_module(): void
    {
        $lines = $this->detailsField($this->applications, 'lines');
        $lineProject = $this->referenceField($this->childOf($lines), 'line_project', $this->projects);
        $project = $this->recordIn($this->projects);
        [$low, $high] = [$this->recordIn($this->applications), $this->recordIn($this->applications)];
        $this->refer($this->rowOf($high, $lineProject), $lineProject, $project);
        $this->refer($this->rowOf($high, $lineProject), $lineProject, $project);
        $this->refer($this->rowOf($low, $lineProject), $lineProject, $project);

        $this->assertSame(
            [$this->bundle($lineProject, $this->applications, [$low->id, $high->id], 'lines')],
            app(RecordRelations::class)->referencingByField($project->id),
        );
    }

    /**
     * recordIds は昇順で自分を除く。自分だけが指す項目(明細の行が親を指す・自分の項目が自分を指す)と指す行の無い項目は束を返さず、
     * ほかのレコードを指す行は入らない。
     */
    public function test_record_ids_are_ascending_without_itself_and_other_targets(): void
    {
        $byApplication = $this->referenceField($this->applications, 'project', $this->projects);
        $parent = $this->referenceField($this->projects, 'parent', $this->projects);
        $self = $this->referenceField($this->projects, 'self', $this->projects);
        $phases = $this->childOf($this->detailsField($this->projects, 'phases'));
        $phaseProject = $this->referenceField($phases, 'phase_project', $this->projects);
        $this->referenceField($this->applications, 'unused', $this->projects);
        $onlyOther = $this->referenceField($this->applications, 'only_other', $this->projects);
        [$project, $other] = [$this->recordIn($this->projects), $this->recordIn($this->projects)];
        [$low, $high] = [$this->recordIn($this->applications), $this->recordIn($this->applications)];
        $this->refer($high, $byApplication, $project);
        $this->refer($low, $byApplication, $project);
        $this->refer($this->recordIn($this->applications), $byApplication, $other);
        $this->refer($this->recordIn($this->applications), $onlyOther, $other);
        $this->refer($project, $parent, $project);
        $this->refer($other, $parent, $project);
        $this->refer($project, $self, $project);
        $this->refer($this->rowOf($project, $phaseProject), $phaseProject, $project);

        $this->assertSame([
            $this->bundle($byApplication, $this->applications, [$low->id, $high->id]),
            $this->bundle($parent, $this->projects, [$other->id]),
        ], app(RecordRelations::class)->referencingByField($project->id));
    }

    /**
     * 束の並びは業務モジュール・項目の定義の順。sort_key を作った順(id の順)と逆にして、id だけで並べる誤りと見分ける。sort_key が同じ組で code・id の決め手も見る。
     */
    public function test_bundles_are_ordered_by_module_and_field_definition(): void
    {
        $this->sortModule($this->applications, 2);
        $tieB = $this->sortModule($this->recordModule('tie_b'), 1);
        $tieA = $this->sortModule($this->recordModule('tie_a'), 1);
        $late = $this->referenceField($this->applications, 'late', $this->projects, ['sort_key' => 3]);
        $lines = $this->detailsField($this->applications, 'lines', ['sort_key' => 2]);
        $lineLate = $this->referenceField($this->childOf($lines), 'line_late', $this->projects, ['sort_key' => 2]);
        $lineEarly = $this->referenceField($this->childOf($lines), 'line_early', $this->projects, ['sort_key' => 1]);
        $tiedFirst = $this->referenceField($this->applications, 'tied_first', $this->projects, ['sort_key' => 1]);
        $tiedSecond = $this->referenceField($this->applications, 'tied_second', $this->projects, ['sort_key' => 1]);
        $inB = $this->referenceField($tieB, 'in_b', $this->projects);
        $inA = $this->referenceField($tieA, 'in_a', $this->projects);
        $project = $this->recordIn($this->projects);
        $application = $this->recordIn($this->applications);

        foreach ([$late, $tiedFirst, $tiedSecond] as $field) {
            $this->refer($application, $field, $project);
        }

        foreach ([$lineLate, $lineEarly] as $field) {
            $this->refer($this->rowOf($application, $field), $field, $project);
        }

        [$a, $b] = [$this->recordIn($tieA), $this->recordIn($tieB)];
        $this->refer($a, $inA, $project);
        $this->refer($b, $inB, $project);

        $this->assertSame([
            $this->bundle($inA, $tieA, [$a->id]),
            $this->bundle($inB, $tieB, [$b->id]),
            $this->bundle($tiedFirst, $this->applications, [$application->id]),
            $this->bundle($tiedSecond, $this->applications, [$application->id]),
            $this->bundle($lineEarly, $this->applications, [$application->id], 'lines'),
            $this->bundle($lineLate, $this->applications, [$application->id], 'lines'),
            $this->bundle($late, $this->applications, [$application->id]),
        ], app(RecordRelations::class)->referencingByField($project->id));
    }

    /**
     * 項目の id で絞る(絞りは問い合わせで掛ける)。束を作らない id は、無い・他モジュール参照でない(文字列・明細・ユーザー)・
     * このレコードを指さない各種で確かめる。別の業務モジュールを参照先にする項目は、外部キーのために、この項目からこのレコードを指す行を
     * 作れない(指さない項目と同じ)。
     */
    public function test_filters_by_the_given_field_ids(): void
    {
        $first = $this->referenceField($this->applications, 'first', $this->projects);
        $second = $this->referenceField($this->applications, 'second', $this->projects);
        $notPointing = $this->referenceField($this->applications, 'not_pointing', $this->projects);
        $notReferences = [
            $this->field($this->applications, 'title', RecordFieldType::String)->id,
            $this->detailsField($this->applications, 'lines')->id,
            $this->field($this->applications, 'owner', RecordFieldType::User)->id,
        ];
        $targetsOtherModule = $this->referenceField($this->applications, 'targets_other_module', $this->applications);
        [$project, $other] = [$this->recordIn($this->projects), $this->recordIn($this->projects)];
        $unreferred = $this->recordIn($this->projects);
        $application = $this->recordIn($this->applications);
        $this->refer($application, $first, $project);
        $this->refer($application, $second, $project);
        $this->refer($application, $notPointing, $other);
        $this->refer($application, $targetsOtherModule, $this->recordIn($this->applications));
        $firstBundle = $this->bundle($first, $this->applications, [$application->id]);
        $secondBundle = $this->bundle($second, $this->applications, [$application->id]);
        $relations = app(RecordRelations::class);
        [$filteredSql, $filtered] = $this->recordingQueries(
            fn () => $relations->referencingByField($project->id, [$second->id]),
        );

        $this->assertNotSame([], preg_grep('/"record_field_id" in/', $filteredSql));
        $this->assertSame([
            [$secondBundle],
            [$firstBundle, $secondBundle],
            [],
            [],
            [$firstBundle, $secondBundle],
            [],
        ], [
            $filtered,
            $relations->referencingByField($project->id, [$second->id, $first->id]),
            $relations->referencingByField($project->id, []),
            $relations->referencingByField(
                $project->id,
                [999_999_999, ...$notReferences, $targetsOtherModule->id, $notPointing->id],
            ),
            $relations->referencingByField($project->id, null),
            $relations->referencingByField($unreferred->id, null),
        ]);
    }

    /** レコードが無い・明細の行なら、絞りが何であっても 404。 */
    public function test_a_missing_record_and_a_details_row_are_not_found(): void
    {
        $lines = $this->detailsField($this->projects, 'lines');
        $row = $this->rowOf($this->recordIn($this->projects), $this->field($this->childOf($lines), 'memo'));

        $cases = [[999_999_999, null], [$row->id, null], [999_999_999, []], [$row->id, [999_999_999]]];

        foreach ($cases as [$recordId, $fieldIds]) {
            $this->assertThrows(
                fn () => app(RecordRelations::class)->referencingByField($recordId, $fieldIds),
                ModelNotFoundException::class,
            );
        }
    }

    /**
     * 指す行が 1 つのときと 10 のときで問い合わせの数が同じ。主体の判定の SQL が出ない。
     */
    public function test_the_number_of_queries_does_not_depend_on_the_referring_rows_and_no_subject_is_judged(): void
    {
        $byApplication = $this->referenceField($this->applications, 'project', $this->projects);
        $lines = $this->detailsField($this->applications, 'lines');
        $lineProject = $this->referenceField($this->childOf($lines), 'line_project', $this->projects);
        [$one, $many] = [$this->recordIn($this->projects), $this->recordIn($this->projects)];
        $this->refer($this->recordIn($this->applications), $byApplication, $one);

        for ($i = 0; $i < 5; $i++) {
            $application = $this->recordIn($this->applications);
            $this->refer($application, $byApplication, $many);
            $this->refer($this->rowOf($application, $lineProject), $lineProject, $many);
        }

        $relations = app(RecordRelations::class);
        [$oneSql, $oneBundles] = $this->recordingQueries(fn () => $relations->referencingByField($one->id));
        [$manySql, $manyBundles] = $this->recordingQueries(fn () => $relations->referencingByField($many->id));
        $sizes = fn (array $bundles) => array_map(fn (array $bundle) => count($bundle['recordIds']), $bundles);

        $this->assertSame([[1], [5, 5]], [$sizes($oneBundles), $sizes($manyBundles)]);
        $this->assertNotSame([], $oneSql);
        $this->assertSame(count($oneSql), count($manySql));
        $this->assertSame([], preg_grep('/authz_|created_by_user_id/', [...$oneSql, ...$manySql]));
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

    private function sortModule(RecordModule $module, int $sortKey): RecordModule
    {
        $module->forceFill(['sort_key' => $sortKey])->save();

        return $module;
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
