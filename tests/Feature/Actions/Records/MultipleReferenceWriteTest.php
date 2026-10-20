<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Records\RecordFieldType;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 複数選べる参照の書き込み(docs の 設計/業務データの入れ物/項目と規則/README.md 2 節の表・5 節、項目と規則/規則と業務処理/失敗と定義の変更.md 10 節の
 * キーの形、画面と検査.md 13 節の「複数選べる参照」): 配列の順に入る、形・数・重なり・相手の誤り、空の配列、更新で配列が全体を置き換える。
 */
final class MultipleReferenceWriteTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;

    private const RETIRED = [['2020-01-01', '2026-04-01']];

    public function test_stores_the_targets_in_the_input_order(): void
    {
        $members = $this->membersField();
        [$first, $second, $third] = [$this->user(), $this->user(), $this->user()];

        $record = $this->create(['members' => [$third->id, $first->id, $second->id]]);

        $this->assertSame([[$third->id, 0], [$first->id, 1], [$second->id, 2]], $this->rows($record, $members));
    }

    public function test_rejects_a_wrong_shape_too_many_or_repeated_targets_with_the_field_key(): void
    {
        $this->membersField();
        $user = $this->user();
        $wrong = ['values.members' => 'membersの値が正しくありません'];

        $this->assertSame($wrong, $this->validationErrors(fn () => $this->create(['members' => $user->id])));
        $this->assertSame($wrong, $this->validationErrors(fn () => $this->create(['members' => [$user->id, '1']])));
        $this->assertSame($wrong, $this->validationErrors(fn () => $this->create(['members' => ['a' => $user->id]])));
        $this->assertSame(
            ['values.members' => 'membersに同じ値が重複しています'],
            $this->validationErrors(fn () => $this->create(['members' => [$user->id, $user->id]])),
        );
        $this->assertSame(
            ['values.members' => 'membersは 100 件までにしてください'],
            $this->validationErrors(fn () => $this->create(['members' => range(1, 101)])),
        );
    }

    public function test_a_wrong_shape_is_rejected_without_looking_up_the_targets(): void
    {
        $this->membersField();
        $this->field($this->module(), 'sections', RecordFieldType::Department, ['is_multiple' => true]);
        $user = $this->user();
        $wrong = [
            ['members' => range(1, 101)],
            ['members' => [$user->id, $user->id]],
            ['members' => ['a' => $user->id]],
            ['sections' => range(1, 101)],
        ];

        foreach ($wrong as $values) {
            $errors = [];
            $lookups = $this->targetLookups(function () use ($values, &$errors) {
                $errors = $this->validationErrors(fn () => $this->create($values));
            });

            $this->assertSame([], $lookups);
            $this->assertCount(1, $errors);
        }
        $this->assertNotSame([], $this->targetLookups(fn () => $this->create(['members' => [$user->id]])));
    }

    public function test_rejects_targets_outside_the_rules_with_the_index_key(): void
    {
        $this->membersField();
        $retired = $this->user(self::RETIRED);

        $input = ['members' => [$this->user()->id, $retired->id, 999_999_999]];

        $errors = $this->validationErrors(fn () => $this->create($input));

        $wrong = 'membersの値が正しくありません';
        $this->assertSame(['values.members.1' => $wrong, 'values.members.2' => $wrong], $errors);
    }

    public function test_accepts_100_targets(): void
    {
        $members = $this->membersField();
        $ids = array_map(fn () => $this->user()->id, range(1, 100));

        $record = $this->create(['members' => $ids]);

        $this->assertSame($ids, array_column($this->rows($record, $members), 0));
    }

    public function test_a_field_of_a_detail_row_follows_the_same_rules_and_keys(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $this->field($this->childOf($tasks), 'assignees', RecordFieldType::User, ['is_multiple' => true]);
        [$user, $retired] = [$this->user(), $this->user(self::RETIRED)];
        $row = fn (array $ids) => ['tasks' => [new RecordDetailRowInput(null, ['assignees' => $ids])]];

        $repeated = $this->validationErrors(fn () => $this->create([], $row([$user->id, $user->id])));
        $invalid = $this->validationErrors(fn () => $this->create([], $row([$user->id, $retired->id])));

        $this->assertSame(['details.tasks.0.values.assignees' => 'assigneesに同じ値が重複しています'], $repeated);
        $this->assertSame(['details.tasks.0.values.assignees.1' => 'assigneesの値が正しくありません'], $invalid);
    }

    public function test_an_empty_array_is_empty(): void
    {
        $members = $this->membersField();
        $this->field($this->module(), 'sections', RecordFieldType::Department, [
            'is_multiple' => true,
            'is_required' => true,
        ]);
        $department = $this->department(DepartmentSet::factory()->create());

        $errors = $this->validationErrors(fn () => $this->create(['members' => [], 'sections' => []]));
        $record = $this->create(['members' => [], 'sections' => [$department->id]]);

        $this->assertSame(['values.sections' => 'sectionsを入力してください'], $errors);
        $this->assertSame([], $this->rows($record, $members));
    }

    public function test_an_update_replaces_the_whole_value_in_the_input_order(): void
    {
        $members = $this->membersField();
        [$a, $b, $c, $d] = [$this->user(), $this->user(), $this->user(), $this->user()];
        $record = $this->create(['members' => [$a->id, $b->id, $c->id]]);

        $this->update($record, ['members' => [$c->id, $d->id]]);
        $reduced = $this->rows($record, $members);
        $this->update($record, ['members' => [$d->id, $c->id]]);

        $this->assertSame([[$c->id, 0], [$d->id, 1]], $reduced);
        $this->assertSame([[$d->id, 0], [$c->id, 1]], $this->rows($record, $members));
    }

    public function test_an_update_checks_only_targets_that_are_not_in_the_current_value(): void
    {
        $members = $this->membersField();
        $record = Record::factory()->create(['record_module_id' => $members->record_module_id]);
        $kept = $this->user(self::RETIRED);
        $this->refer($record, $members, $kept, 0);
        $added = $this->user();

        $this->update($record, ['members' => [$added->id, $kept->id]]);
        $another = $this->user(self::RETIRED);
        $input = ['members' => [$added->id, $kept->id, $another->id]];
        $errors = $this->validationErrors(fn () => $this->update($record, $input));

        $this->assertSame([[$added->id, 0], [$kept->id, 1]], $this->rows($record, $members));
        $this->assertSame(['values.members.2' => 'membersの値が正しくありません'], $errors);
    }

    public function test_an_update_that_keeps_the_value_does_not_touch_the_rows(): void
    {
        $members = $this->membersField();
        $this->field($this->module(), 'title');
        [$a, $b] = [$this->user(), $this->user()];
        $record = $this->create(['members' => [$a->id, $b->id]]);
        $before = $this->rowIds($record);

        $this->update($record, ['members' => [$a->id, $b->id], 'title' => '変更']);
        $this->update($record, ['title' => '再変更']);

        $this->assertSame($before, $this->rowIds($record));
        $this->assertSame([[$a->id, 0], [$b->id, 1]], $this->rows($record, $members));
    }

    /**
     * 業務モジュール projects と、ユーザー(複数)の項目 members。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function membersField(array $attributes = []): RecordField
    {
        $module = $this->recordModule('projects');

        return $this->field($module, 'members', RecordFieldType::User, ['is_multiple' => true, ...$attributes]);
    }

    /**
     * 処理の中で走った、ユーザー・組織の表を読む問い合わせ(参照の相手を確かめる問い合わせ)。
     *
     * @return list<string>
     */
    private function targetLookups(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $isLookup = fn (string $query) => preg_match('/from "(users|departments)"/', $query) === 1;

        return array_values(array_filter($queries, $isLookup));
    }

    private function module(): RecordModule
    {
        return RecordModule::query()->where('code', 'projects')->sole();
    }

    private function refer(Record $record, RecordField $field, User $user, int $sortKey): void
    {
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => $user->id,
            'sort_key' => $sortKey,
            'is_multiple' => true,
        ]);
    }

    /**
     * 項目の参照の行(相手の id と sort_key。sort_key の順)。
     *
     * @return list<array{int|null, int}>
     */
    private function rows(Record $record, RecordField $field): array
    {
        return array_values(RecordReference::query()
            ->where('record_id', $record->id)
            ->where('record_field_id', $field->id)
            ->orderBy('sort_key')
            ->get()
            ->map(fn (RecordReference $row) => [$row->user_id ?? $row->department_id, $row->sort_key])
            ->all());
    }

    /**
     * レコードの参照の行の id(sort_key の順。行に触れていなければ変わらない)。
     *
     * @return list<int>
     */
    private function rowIds(Record $record): array
    {
        return array_values(RecordReference::query()
            ->where('record_id', $record->id)
            ->orderBy('sort_key')
            ->pluck('id')
            ->all());
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<RecordDetailRowInput>>  $details
     */
    private function create(array $values, array $details = []): Record
    {
        $id = app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput($values, $details));

        return Record::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function update(Record $record, array $values): void
    {
        $version = Record::query()->findOrFail($record->id)->version();

        app(UpdateRecord::class)->execute(
            $this->contextFor(),
            'projects',
            $record->id,
            new RecordInput($values),
            $version,
        );
    }
}
