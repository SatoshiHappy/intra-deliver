<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\DepartmentSet;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * レコードを登録する業務処理(docs の 設計/業務データの入れ物/項目と規則/README.md 2 節・3 節・5 節・6 節・設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9 節、失敗と定義の変更.md 10 節、画面と検査.md 13 節の型ごとの検証・既定値・
 * 評価の順・参照・明細の登録の分。判定は 認可とメニュー/README.md 2.2 の create)。
 */
final class CreateRecordTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;

    public function test_writes_normalized_values_keyed_by_field_id(): void
    {
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title');
        $budget = $this->field($module, 'budget', RecordFieldType::Integer);
        $rate = $this->field($module, 'rate', RecordFieldType::Decimal);
        $startsAt = $this->field($module, 'starts_at', RecordFieldType::Datetime);
        $urgent = $this->field($module, 'urgent', RecordFieldType::Boolean);
        $status = $this->choiceField($module, 'status', ['open', 'done']);

        $record = $this->create('projects', ['title' => '  社内ポータル  ', 'budget' => ' 1200 ', 'rate' => '12.50', 'starts_at' => '2026-10-11T09:30+09:00', 'urgent' => true, 'status' => ' open ']);

        $this->assertEquals([
            $title->id => '社内ポータル',
            $budget->id => 1200,
            $rate->id => '12.5',
            $startsAt->id => '2026-10-11T00:30:00Z',
            $urgent->id => true,
            $status->id => 'open',
        ], $record->refresh()->field_values);
        $this->assertSame([null, null, null, 0], [$record->created_by_user_id, $record->updated_by_user_id, $record->parent_record_id, $record->sort_key]);
    }

    public function test_an_empty_or_blank_string_is_not_kept(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title');
        $this->field($module, 'note');

        $record = $this->create('projects', ['title' => '   ', 'note' => '']);

        $this->assertSame([], $record->refresh()->field_values);
    }

    public function test_fills_default_values_only_for_fields_without_a_key(): void
    {
        $module = $this->recordModule('projects');
        $status = $this->choiceField($module, 'status', ['open', 'done'], ['default_value' => 'open']);
        $budget = $this->field($module, 'budget', RecordFieldType::Integer, ['default_value' => 100]);
        $urgent = $this->field($module, 'urgent', RecordFieldType::Boolean);
        $checked = $this->field($module, 'checked', RecordFieldType::Boolean, ['default_value' => true]);

        $record = $this->create('projects', ['budget' => null]);

        $this->assertEquals([$status->id => 'open', $urgent->id => false, $checked->id => true], $record->refresh()->field_values);
        $this->assertArrayNotHasKey($budget->id, $record->field_values ?? []);
    }

    public function test_records_the_user_of_the_access_context_as_creator_and_updater(): void
    {
        $this->recordModule('projects');
        $user = $this->user();

        $record = Record::query()->findOrFail(app(CreateRecord::class)->execute($this->contextFor($user->id), 'projects', new RecordInput));

        $this->assertSame([$user->id, $user->id], [$record->created_by_user_id, $record->updated_by_user_id]);
    }

    public function test_returns_the_id_of_the_created_record(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title');

        $id = app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput(['title' => '新']));

        $this->assertSame($module->id, Record::query()->findOrFail($id)->record_module_id);
        $this->assertSame($id, Record::query()->max('id'));
    }

    /**
     * 公開の口は自分のトランザクションを持ち、外側のトランザクションの中では savepoint で外側に含まれる
     * (docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の原子性、同 README.md 9 節)。
     */
    public function test_a_failure_of_the_outer_transaction_leaves_no_created_record(): void
    {
        $this->recordModule('projects');
        $before = Record::query()->count();

        $this->assertThrows(fn () => DB::transaction(function (): void {
            app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput);

            throw new RuntimeException('外側の失敗');
        }), RuntimeException::class, '外側の失敗');

        $this->assertSame($before, Record::query()->count());
    }

    public function test_rejects_unknown_keys_misplaced_details_and_wrong_types_together_with_keys(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'budget', RecordFieldType::Integer);
        $this->field($module, 'starts_at', RecordFieldType::Datetime);
        $this->detailsField($module, 'tasks');

        $errors = $this->validationErrors(fn () => $this->create('projects', ['nope' => 'x', 'tasks' => [], 'budget' => '1.5', 'starts_at' => '2026-10-11T09:30'], ['budget' => [new RecordDetailRowInput]]));

        $this->assertSame([
            'details.budget' => '明細の項目がありません',
            'values.nope' => '項目がありません',
            'values.tasks' => 'tasksは明細(details)で指定してください',
            'values.budget' => 'budgetは整数で入力してください',
            'values.starts_at' => 'starts_atは時差つきの日時(例 2026-10-11T09:30+09:00)で入力してください',
        ], $errors);
        $this->assertSame(0, Record::query()->where('record_module_id', $module->id)->count());
    }

    public function test_does_not_evaluate_required_or_rules_while_the_input_has_errors(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title', attributes: ['is_required' => true]);
        $this->field($module, 'budget', RecordFieldType::Integer);

        $errors = $this->validationErrors(fn () => $this->create('projects', ['budget' => 'abc']));

        $this->assertSame(['values.budget' => 'budgetは整数で入力してください'], $errors);
    }

    public function test_returns_the_failures_of_the_parent_and_the_detail_rows_together(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title', attributes: ['is_required' => true]);
        $tasks = $this->detailsField($module, 'tasks');
        $this->field($this->childOf($tasks), 'task_name', attributes: ['is_required' => true]);

        $errors = $this->validationErrors(fn () => $this->create('projects', [], ['tasks' => [new RecordDetailRowInput(values: ['task_name' => 'a']), new RecordDetailRowInput]]));

        $this->assertSame(['values.title' => 'titleを入力してください', 'details.tasks.1.values.task_name' => 'task_nameを入力してください'], $errors);
        $this->assertSame(0, Record::query()->where('record_module_id', $module->id)->count());
    }

    public function test_creates_detail_rows_in_the_order_of_the_input_with_their_defaults(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $child = $this->childOf($tasks);
        $name = $this->field($child, 'task_name');
        $state = $this->field($child, 'state', attributes: ['default_value' => '未着手']);

        $record = $this->create('projects', [], ['tasks' => [new RecordDetailRowInput(values: ['task_name' => '設計']), new RecordDetailRowInput(values: ['task_name' => '実装', 'state' => '着手'])]]);

        $rows = Record::query()->where('parent_record_id', $record->id)->orderBy('sort_key')->get();
        $this->assertSame([0, 1], $rows->pluck('sort_key')->all());
        $this->assertEquals([[$name->id => '設計', $state->id => '未着手'], [$name->id => '実装', $state->id => '着手']], $rows->pluck('field_values')->all());
        $this->assertSame([$child->id, $child->id], $rows->pluck('record_module_id')->all());
        $this->assertSame([$module->id, $module->id], $rows->pluck('parent_record_module_id')->all());
    }

    public function test_rejects_detail_rows_with_an_id_or_more_than_100_rows(): void
    {
        $module = $this->recordModule('projects');
        $this->detailsField($module, 'tasks');
        $this->detailsField($module, 'notes');

        $errors = $this->validationErrors(fn () => $this->create('projects', [], [
            'tasks' => [new RecordDetailRowInput, new RecordDetailRowInput(id: 1)],
            'notes' => array_fill(0, 101, new RecordDetailRowInput),
        ]));

        $this->assertSame(['details.tasks.1.id' => '新しいレコードの明細の行に id は設定できません', 'details.notes' => 'notesは 100 行までにしてください'], $errors);
    }

    public function test_writes_references_to_a_valid_user_department_and_record(): void
    {
        $module = $this->recordModule('projects');
        $customers = $this->recordModule('customers');
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $section = $this->field($module, 'section', RecordFieldType::Department);
        $customer = $this->referenceField($module, 'customer', $customers);
        $user = $this->user();
        $department = $this->department(DepartmentSet::factory()->create());
        $customerRecord = Record::factory()->create(['record_module_id' => $customers->id]);

        $record = $this->create('projects', ['owner' => $user->id, 'section' => $department->id, 'customer' => $customerRecord->id]);

        $references = RecordReference::query()->where('record_id', $record->id)->get()->keyBy('record_field_id');
        $this->assertSame($user->id, $references->get($owner->id)?->user_id);
        $this->assertSame($department->id, $references->get($section->id)?->department_id);
        $this->assertSame([$customers->id, $customerRecord->id], [$references->get($customer->id)?->referenced_record_module_id, $references->get($customer->id)?->referenced_record_id]);
        $this->assertSame([], $record->refresh()->field_values);
    }

    public function test_rejects_references_to_invalid_targets(): void
    {
        $module = $this->recordModule('projects');
        $customers = $this->recordModule('customers');
        $secrets = $this->recordModule('secrets', [RecordModuleAction::Create]);
        $this->field($module, 'owner', RecordFieldType::User);
        $this->field($module, 'section', RecordFieldType::Department);
        $this->referenceField($module, 'customer', $customers);
        $this->referenceField($module, 'other_customer', $customers);
        $this->referenceField($module, 'secret', $secrets);
        $this->referenceField($module, 'detail', $customers);
        $retired = $this->user([['2020-01-01', '2026-04-01']]);
        $closed = $this->department(DepartmentSet::factory()->create(), [['2020-01-01', '2026-04-01']]);
        $detailRow = Record::factory()->detail()->create();

        $errors = $this->validationErrors(fn () => $this->create('projects', [
            'owner' => $retired->id,
            'section' => $closed->id,
            'customer' => Record::factory()->create(['record_module_id' => $module->id])->id,
            'other_customer' => '1',
            'secret' => Record::factory()->create(['record_module_id' => $secrets->id])->id,
            'detail' => $detailRow->id,
        ]));

        $this->assertSame(['owner', 'section', 'customer', 'other_customer', 'secret', 'detail'], array_map(fn (string $key) => substr($key, 7), array_keys($errors)));
        $this->assertSame('ownerの値が正しくありません', $errors['values.owner']);
    }

    public function test_rejects_without_the_create_permission_and_writes_nothing(): void
    {
        $module = $this->recordModule('projects', [RecordModuleAction::Read, RecordModuleAction::Update, RecordModuleAction::Delete]);

        $this->expectException(AuthorizationException::class);

        try {
            $this->create('projects', []);
        } finally {
            $this->assertSame(0, Record::query()->where('record_module_id', $module->id)->count());
        }
    }

    public function test_a_missing_module_or_a_details_module_is_not_found(): void
    {
        $module = $this->recordModule('projects');
        $this->detailsField($module, 'tasks');

        foreach (['nothing', 'tasks_rows'] as $code) {
            try {
                $this->create($code, []);
                $this->fail("{$code} が見つかった");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * ゲストとして登録する。
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, list<RecordDetailRowInput>>  $details
     */
    private function create(string $moduleCode, array $values, array $details = [], ?AccessContext $context = null): Record
    {
        return Record::query()->findOrFail(app(CreateRecord::class)->execute($context ?? $this->contextFor(), $moduleCode, new RecordInput($values, $details)));
    }
}
