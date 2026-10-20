<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Actions\Records\DeleteRecordField;
use App\Actions\Records\UpdateRecordField;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 持ち主の規則(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2、失敗と定義の変更.md 11 節、画面と検査.md 13 節の「持ち主」。口の形 = 設計/ワークフロー/
 * 業務データとの接続/公開の口.md 7 節): 持ち主のあるレコードの値は持ち主の鍵を渡した更新でしか変わらず、削除と、その業務モジュールの項目の削除は
 * 拒む(422)。テストの持ち主の実装をタグで登録し、業務モジュールとレコードはテストで作る。
 */
final class RecordHoldRuleTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    private const HOLDER = 'workflow-case:1';

    private RecordModule $module;

    private RecordField $title;

    private Record $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = $this->recordModule('projects');
        $this->title = $this->field($this->module, 'title');
        $this->record = Record::factory()->create(['record_module_id' => $this->module->id, 'field_values' => [$this->title->id => '旧']]);
    }

    /**
     * @param  string|null  $holder  更新に渡す鍵(null = 鍵なし)
     */
    #[TestWith([null], '鍵なし')]
    #[TestWith(['workflow-case:2'], '違う鍵')]
    public function test_rejects_updating_a_held_record_without_the_holder_and_changes_nothing(?string $holder): void
    {
        $this->registerRecordHolds([$this->record->id => self::HOLDER]);

        $errors = $this->validationErrors(fn () => $this->update(['title' => '新'], $holder));

        $this->assertSame(['record' => self::HOLD_REASON], $errors);
        $this->assertUnchanged();
    }

    public function test_updates_a_held_record_with_the_holder(): void
    {
        $this->registerRecordHolds([$this->record->id => self::HOLDER]);

        $this->update(['title' => '新'], self::HOLDER);

        $this->assertEquals([$this->title->id => '新'], $this->record->refresh()->field_values);
    }

    public function test_the_holder_rule_comes_before_validating_the_values(): void
    {
        $this->field($this->module, 'budget', RecordFieldType::Integer);
        $this->registerRecordHolds([$this->record->id => self::HOLDER], '別の文言');

        $errors = $this->validationErrors(fn () => $this->update(['budget' => 'x', 'unknown' => 1]));

        $this->assertSame(['record' => '別の文言'], $errors);
    }

    public function test_authorization_comes_before_the_holder_rule(): void
    {
        $denied = $this->recordModule('denied', [RecordModuleAction::Read]);
        $record = Record::factory()->create(['record_module_id' => $denied->id]);
        $this->registerRecordHolds([$record->id => self::HOLDER]);

        $this->assertThrows(fn () => app(UpdateRecord::class)->execute($this->contextFor(), 'denied', $record->id, new RecordInput, $record->version()), AuthorizationException::class);
        $this->assertThrows(fn () => app(DeleteRecord::class)->execute($this->contextFor(), 'denied', $record->id), AuthorizationException::class);
    }

    /**
     * 明細の行は親の操作の一部なので、持ち主は親のレコードで見る(明細の行を変える更新も拒む)。
     */
    public function test_rejects_changing_the_detail_rows_of_a_held_record(): void
    {
        $tasks = $this->detailsField($this->module, 'tasks');
        $this->field($this->childOf($tasks), 'name');
        $this->registerRecordHolds([$this->record->id => self::HOLDER]);

        $errors = $this->validationErrors(fn () => $this->update([], details: ['tasks' => [new RecordDetailRowInput(values: ['name' => '行'])]]));

        $this->assertSame(['record' => self::HOLD_REASON], $errors);
        $this->assertSame(0, Record::query()->where('parent_record_id', $this->record->id)->count());
    }

    /**
     * 持ち主が 2 つなら、渡した鍵と違う持ち主が必ずあるので、どちらの鍵でも書けない。
     */
    #[TestWith(['workflow-case:1'])]
    #[TestWith(['other:9'])]
    public function test_two_holders_reject_updating_with_either_holder(string $holder): void
    {
        $this->registerRecordHolds([$this->record->id => 'workflow-case:1']);
        $this->registerRecordHolds([$this->record->id => 'other:9'], 'ほかの持ち主があるので変えられません');

        $errors = $this->validationErrors(fn () => $this->update(['title' => '新'], $holder));

        $this->assertSame(['record'], array_keys($errors));
        $this->assertUnchanged();
    }

    public function test_rejects_deleting_a_held_record_and_deletes_nothing(): void
    {
        $this->registerRecordHolds([$this->record->id => self::HOLDER]);

        $errors = $this->validationErrors(fn () => app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $this->record->id));

        $this->assertSame(['record' => self::HOLD_REASON], $errors);
        $this->assertModelExists($this->record);
    }

    public function test_without_a_holder_of_the_record_updating_and_deleting_work_as_before(): void
    {
        $other = Record::factory()->create(['record_module_id' => $this->module->id]);
        $this->registerRecordHolds([$other->id => self::HOLDER]);

        $this->update(['title' => '新']);
        $this->assertEquals([$this->title->id => '新'], $this->record->refresh()->field_values);

        app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $this->record->id);
        $this->assertModelMissing($this->record);
    }

    public function test_rejects_deleting_a_field_of_a_module_with_a_held_record_and_deletes_nothing(): void
    {
        $this->registerRecordHolds([$this->record->id => self::HOLDER]);

        $errors = $this->validationErrors(fn () => app(DeleteRecordField::class)->execute('projects', 'title'));

        $this->assertSame(['field' => self::HOLD_REASON], $errors);
        $this->assertModelExists($this->title);
    }

    /**
     * 明細のモジュールの項目の削除は、親の業務モジュールのレコードの持ち主で拒む。
     */
    public function test_rejects_deleting_a_field_of_the_details_module_by_a_held_record_of_the_parent(): void
    {
        $tasks = $this->detailsField($this->module, 'tasks');
        $name = $this->field($this->childOf($tasks), 'name');
        $this->registerRecordHolds([$this->record->id => self::HOLDER]);

        $errors = $this->validationErrors(fn () => app(DeleteRecordField::class)->execute($this->childOf($tasks)->code, 'name'));

        $this->assertSame(['field' => self::HOLD_REASON], $errors);
        $this->assertModelExists($name);
    }

    public function test_adding_a_field_and_changing_its_attributes_are_not_rejected_by_a_holder(): void
    {
        $this->registerRecordHolds([$this->record->id => self::HOLDER]);

        app(AddRecordField::class)->execute('projects', ['code' => 'memo', 'name' => 'メモ', 'type' => 'string']);
        app(UpdateRecordField::class)->execute('projects', 'title', ['name' => '件名', 'is_required' => true]);

        $this->assertSame(['title' => '件名', 'memo' => 'メモ'], $this->module->recordFields()->orderBy('id')->pluck('name', 'code')->all());
        $this->assertTrue($this->title->refresh()->is_required);
    }

    public function test_deleting_a_field_works_as_before_when_the_held_record_is_in_another_module(): void
    {
        $other = $this->recordModule('others');
        $this->registerRecordHolds([Record::factory()->create(['record_module_id' => $other->id])->id => self::HOLDER]);

        app(DeleteRecordField::class)->execute('projects', 'title');

        $this->assertModelMissing($this->title);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<RecordDetailRowInput>>  $details
     */
    private function update(array $values, ?string $holder = null, array $details = []): void
    {
        app(UpdateRecord::class)->execute($this->contextFor(), 'projects', $this->record->id, new RecordInput($values, $details), $this->record->refresh()->version(), $holder);
    }

    private function assertUnchanged(): void
    {
        $this->assertEquals([$this->title->id => '旧'], $this->record->refresh()->field_values);
    }
}
