<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecordField;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * レコードを更新する業務処理(docs の 設計/業務データの入れ物/項目と規則/README.md 3 節・5 節・6 節・設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2、画面と検査.md 13 節の既定値・評価の順・参照・明細の更新の分。
 * 判定は 認可とメニュー/README.md 2.2 の update)。
 */
final class UpdateRecordTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;

    private const STALE = 'このレコードは、開いた後に更新されています。画面を開き直してから、もう一度保存してください。';

    public function test_overlays_the_input_on_the_current_values_without_defaults_and_drops_unknown_keys(): void
    {
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title');
        $budget = $this->field($module, 'budget', RecordFieldType::Integer, ['default_value' => 100]);
        $note = $this->field($module, 'note');
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '旧', $note->id => 'メモ', 999_999 => '消した項目の値']]);
        $user = $this->user();

        $this->update($record, $this->currentVersion($record), ['title' => '新', 'note' => null], userId: $user->id);

        $this->assertEquals([$title->id => '新'], $record->refresh()->field_values);
        $this->assertArrayNotHasKey($budget->id, $record->field_values ?? []);
        $this->assertSame($user->id, $record->updated_by_user_id);
    }

    /**
     * 公開の口は自分のトランザクションを持ち、外側のトランザクションの中では savepoint で外側に含まれる
     * (docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の原子性、同 写しとつながる申請.md 9 節)。
     */
    public function test_a_failure_of_the_outer_transaction_leaves_the_record_unchanged(): void
    {
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title');
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '旧']]);

        $this->assertThrows(fn () => DB::transaction(function () use ($record): void {
            $this->update($record, $this->currentVersion($record), ['title' => '新']);

            throw new RuntimeException('外側の失敗');
        }), RuntimeException::class, '外側の失敗');

        $this->assertEquals([$title->id => '旧'], $record->refresh()->field_values);
    }

    public function test_validates_current_values_outside_the_input_with_the_current_definition(): void
    {
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title', attributes: ['max_length' => 3]);
        $this->field($module, 'note');
        $this->field($module, 'owner_name', attributes: ['is_required' => true]);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '長いタイトル']]);

        $errors = $this->validationErrors(fn () => $this->update($record, $this->currentVersion($record), ['note' => 'x']));

        $this->assertSame(['values.title' => 'titleは 3 文字以内で入力してください', 'values.owner_name' => 'owner_nameを入力してください'], $errors);
    }

    public function test_keeps_unchanged_references_without_checking_them_again(): void
    {
        $module = $this->recordModule('projects');
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $retired = $this->user([['2020-01-01', '2026-04-01']]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        RecordReference::factory()->create(['record_module_id' => $module->id, 'record_id' => $record->id, 'record_field_id' => $owner->id, 'user_id' => $retired->id]);

        $this->update($record, $this->currentVersion($record), ['owner' => $retired->id]);
        $errors = $this->validationErrors(fn () => $this->update($record, $this->currentVersion($record), ['owner' => $this->user([['2020-01-01', '2026-01-01']])->id]));

        $this->assertSame(['values.owner' => 'ownerの値が正しくありません'], $errors);
        $this->assertSame($retired->id, RecordReference::query()->where('record_id', $record->id)->sole()->user_id);
    }

    public function test_changes_and_clears_references(): void
    {
        $module = $this->recordModule('projects');
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $reviewer = $this->field($module, 'reviewer', RecordFieldType::User);
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        RecordReference::factory()->create(['record_module_id' => $module->id, 'record_id' => $record->id, 'record_field_id' => $owner->id, 'user_id' => $this->user()->id]);
        RecordReference::factory()->create(['record_module_id' => $module->id, 'record_id' => $record->id, 'record_field_id' => $reviewer->id, 'user_id' => $this->user()->id]);
        $next = $this->user();

        $this->update($record, $this->currentVersion($record), ['owner' => $next->id, 'reviewer' => null]);

        $this->assertSame([$owner->id => $next->id], RecordReference::query()->where('record_id', $record->id)->pluck('user_id', 'record_field_id')->all());
    }

    public function test_rejects_a_reference_to_the_record_itself_or_from_a_detail_row_to_its_parent(): void
    {
        $module = $this->recordModule('projects');
        $this->referenceField($module, 'related', $module);
        $tasks = $this->detailsField($module, 'tasks');
        $this->referenceField($this->childOf($tasks), 'origin', $module);
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $row = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id]);

        $errors = $this->validationErrors(fn () => $this->update($record, $this->currentVersion($record), ['related' => $record->id], ['tasks' => [new RecordDetailRowInput($row->id, ['origin' => $record->id])]]));

        $this->assertSame(['values.related', 'details.tasks.0.values.origin'], array_keys($errors));
    }

    public function test_does_not_rewrite_detail_rows_that_lack_the_key_of_a_boolean_added_later(): void
    {
        [$module, $record, $rows] = $this->projectWithTasks(['設計', '実装']);
        $this->field($module, 'title');
        $this->field(RecordModule::query()->findOrFail($rows[0]->record_module_id), 'is_done', RecordFieldType::Boolean);
        $snapshot = fn () => Record::query()->whereKey(array_map(fn (Record $row) => $row->id, $rows))->orderBy('id')->get(['id', 'field_values', 'updated_at', 'updated_by_user_id'])->toArray();
        $before = $snapshot();
        $this->travel(1)->hours();

        $this->update($record, $this->currentVersion($record), ['title' => '新'], userId: $this->user()->id);

        $this->assertSame($before, $snapshot());
    }

    public function test_drops_keys_of_deleted_fields_from_the_parent_but_does_not_rewrite_untouched_detail_rows(): void
    {
        [$module, $record, $rows] = $this->projectWithTasks(['設計', '実装']);
        $child = RecordModule::query()->findOrFail($rows[0]->record_module_id);
        $memo = $this->field($module, 'memo');
        $note = $this->field($child, 'note');
        $this->field($module, 'title');
        $record->update(['field_values' => [$memo->id => 'メモ']]);

        foreach ($rows as $row) {
            $row->update(['field_values' => [...($row->field_values ?? []), $note->id => '注記']]);
        }

        app(DeleteRecordField::class)->execute('projects', 'memo');
        app(DeleteRecordField::class)->execute($child->code, 'note');
        $snapshot = fn () => Record::query()->whereKey(array_map(fn (Record $row) => $row->id, $rows))->orderBy('id')->get(['id', 'field_values', 'updated_at', 'updated_by_user_id'])->toArray();
        $before = $snapshot();
        $this->travel(1)->hours();

        $this->update($record, $this->currentVersion($record), ['title' => '新'], userId: $this->user()->id);

        $this->assertArrayNotHasKey($memo->id, $record->refresh()->field_values ?? []);
        $this->assertSame($before, $snapshot());
        $this->assertArrayHasKey($note->id, $before[0]['field_values']);
    }

    public function test_replaces_detail_rows_changing_adding_removing_and_reordering(): void
    {
        [$module, $record, $rows, $name] = $this->projectWithTasks(['設計', '実装', '試験']);

        $this->update($record, $this->currentVersion($record), [], ['tasks' => [
            new RecordDetailRowInput($rows[2]->id, ['task_name' => '試験']),
            new RecordDetailRowInput($rows[0]->id, ['task_name' => '基本設計']),
            new RecordDetailRowInput(values: ['task_name' => 'リリース']),
        ]]);

        $after = Record::query()->where('parent_record_id', $record->id)->orderBy('sort_key')->get();
        $this->assertSame([$rows[2]->id, $rows[0]->id], $after->take(2)->pluck('id')->all());
        $this->assertSame(['試験', '基本設計', 'リリース'], $after->map(fn (Record $row) => $row->field_values[$name->id] ?? null)->all());
        $this->assertSame([0, 1, 2], $after->pluck('sort_key')->all());
        $this->assertModelMissing($rows[1]);
        $this->assertSame($module->id, $after->last()?->parent_record_module_id);
    }

    public function test_keeps_detail_rows_when_the_input_has_no_details_key_but_validates_them(): void
    {
        [, $record, $rows, $name] = $this->projectWithTasks(['設計', '']);
        $name->update(['is_required' => true]);

        $errors = $this->validationErrors(fn () => $this->update($record, $this->currentVersion($record), []));
        $this->assertSame(['details.tasks.1.values.task_name' => 'task_nameを入力してください'], $errors);

        $name->update(['is_required' => false]);
        $this->update($record, $this->currentVersion($record), []);
        $this->assertSame(2, Record::query()->where('parent_record_id', $record->id)->count());
        $this->assertSame($rows[0]->updated_at?->toIso8601String(), $rows[0]->refresh()->updated_at?->toIso8601String());
    }

    public function test_rejects_detail_row_ids_of_another_parent_or_given_twice(): void
    {
        [$module, $record, $rows] = $this->projectWithTasks(['設計']);
        $other = Record::factory()->create(['record_module_id' => $module->id]);
        $foreign = Record::factory()->create(['record_module_id' => $rows[0]->record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $other->id]);

        $errors = $this->validationErrors(fn () => $this->update($record, $this->currentVersion($record), [], ['tasks' => [
            new RecordDetailRowInput($rows[0]->id),
            new RecordDetailRowInput($foreign->id),
            new RecordDetailRowInput($rows[0]->id),
        ]]));

        $this->assertSame(['details.tasks.1.id' => '明細の行が見つかりません', 'details.tasks.2.id' => '同じ行が 2 度あります'], $errors);
    }

    public function test_requires_at_least_one_detail_row_when_the_details_field_is_required(): void
    {
        [$module, $record] = $this->projectWithTasks(['設計']);
        RecordField::query()->where('record_module_id', $module->id)->where('code', 'tasks')->update(['is_required' => true]);

        $errors = $this->validationErrors(fn () => $this->update($record, $this->currentVersion($record), [], ['tasks' => []]));

        $this->assertSame(['details.tasks' => 'tasksを 1 行以上入力してください'], $errors);
    }

    public function test_rejects_without_the_update_permission_and_finds_no_record_of_another_module(): void
    {
        $this->recordModule('locked', [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Delete]);
        $open = $this->recordModule('projects');
        $locked = Record::factory()->create(['record_module_id' => RecordModule::query()->where('code', 'locked')->sole()->id]);

        try {
            app(UpdateRecord::class)->execute($this->contextFor(), 'locked', $locked->id, new RecordInput, $locked->version());
            $this->fail('更新できた');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ModelNotFoundException::class);
        app(UpdateRecord::class)->execute($this->contextFor(), $open->code, $locked->id, new RecordInput, $locked->version());
    }

    /**
     * 明細の行だけを変えた保存でも親の版は進み(親はいつも書く = 項目と規則/規則と業務処理/README.md 9.2 の 9)、その前の版の更新は 422(record)で、
     * 親の値も明細の行も変わらない(同 9.2 の 2)。
     */
    public function test_an_update_with_the_version_before_a_save_of_only_detail_rows_is_rejected_and_writes_nothing(): void
    {
        [$module, $record, $rows, $name] = $this->projectWithTasks(['設計']);
        $title = $this->field($module, 'title');
        $opened = $record->refresh()->version();
        $this->travel(1)->seconds();
        $this->update($record, $this->currentVersion($record), [], ['tasks' => [new RecordDetailRowInput($rows[0]->id), new RecordDetailRowInput(values: ['task_name' => '追加'])]]);

        $errors = $this->validationErrors(fn () => $this->update($record, $opened, ['title' => '後の保存'], ['tasks' => [new RecordDetailRowInput($rows[0]->id)]]));

        $this->assertNotSame($opened, $record->refresh()->version());
        $this->assertSame(['record' => self::STALE], $errors);
        $this->assertArrayNotHasKey($title->id, $record->field_values ?? []);
        $this->assertSame(['設計', '追加'], Record::query()->where('parent_record_id', $record->id)->orderBy('sort_key')->get()->map(fn (Record $row) => $row->field_values[$name->id] ?? null)->all());
    }

    /**
     * 版 null は版を比べない(呼び手が自分で同時の書き込みを止める = 項目と規則/規則と業務処理/README.md 9.2 の 2)。
     */
    public function test_a_null_version_does_not_compare_the_version(): void
    {
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title');
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $this->travel(1)->seconds();
        $this->update($record, $this->currentVersion($record), ['title' => '先の保存']);

        app(UpdateRecord::class)->execute($this->contextFor(), 'projects', $record->id, new RecordInput(['title' => '後の保存']), null);

        $this->assertEquals([$title->id => '後の保存'], $record->refresh()->field_values);
    }

    /**
     * 明細の項目 tasks(明細のモジュールの項目 task_name)を持つ業務モジュールと、明細の行を持つレコード。
     *
     * @param  list<string>  $names  行の task_name(空文字は値なし)
     * @return array{RecordModule, Record, list<Record>, RecordField}
     */
    private function projectWithTasks(array $names): array
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $name = $this->field($this->childOf($tasks), 'task_name');
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $rows = [];

        foreach ($names as $index => $value) {
            $rows[] = Record::factory()->create([
                'record_module_id' => $tasks->child_record_module_id,
                'parent_record_module_id' => $module->id,
                'parent_record_id' => $record->id,
                'sort_key' => $index,
                'field_values' => $value === '' ? [] : [$name->id => $value],
            ]);
        }

        return [$module, $record, $rows, $name];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<RecordDetailRowInput>>  $details
     * @param  string  $version  開いたときの版(呼び手が明示する。今の版なら {@see self::currentVersion()})
     */
    private function update(Record $record, string $version, array $values, array $details = [], ?int $userId = null): void
    {
        $module = RecordModule::query()->findOrFail($record->record_module_id);

        app(UpdateRecord::class)->execute($this->contextFor($userId), $module->code, $record->id, new RecordInput($values, $details), $version);
    }

    /**
     * 今の版(直前に開いた画面からの保存)。
     */
    private function currentVersion(Record $record): string
    {
        return Record::query()->findOrFail($record->id)->version();
    }
}
