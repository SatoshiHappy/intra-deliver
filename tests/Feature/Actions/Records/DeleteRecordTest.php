<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\DeleteRecord;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * レコードを削除する業務処理(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2、失敗と定義の変更.md 10 節、画面と検査.md 13 節の削除と明細の分、
 * 設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 1。
 * 判定は 認可とメニュー/README.md 2.2 の delete)。
 */
final class DeleteRecordTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use CatchesValidationErrors;

    public function test_deletes_the_record_with_its_detail_rows_and_references_even_if_the_rows_refer_to_other_records(): void
    {
        $module = $this->recordModule('projects');
        $customers = $this->recordModule('customers');
        $tasks = $this->detailsField($module, 'tasks');
        $customerField = $this->referenceField($this->childOf($tasks), 'customer', $customers);
        $customer = Record::factory()->create(['record_module_id' => $customers->id]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $row = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id, 'sort_key' => 0]);
        $reference = RecordReference::factory()->create(['record_module_id' => $row->record_module_id, 'record_id' => $row->id, 'record_field_id' => $customerField->id, 'user_id' => null, 'referenced_record_module_id' => $customers->id, 'referenced_record_id' => $customer->id]);

        app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $record->id);

        $this->assertModelMissing($record);
        $this->assertModelMissing($row);
        $this->assertModelMissing($reference);
        $this->assertModelExists($customer);
    }

    public function test_rejects_deleting_a_referenced_record_with_the_key_record_and_deletes_nothing(): void
    {
        $projects = $this->recordModule('projects');
        $customers = $this->recordModule('customers');
        $customer = Record::factory()->create(['record_module_id' => $customers->id]);
        $project = Record::factory()->create(['record_module_id' => $projects->id]);
        RecordReference::factory()->create(['record_module_id' => $projects->id, 'record_id' => $project->id, 'record_field_id' => $this->referenceField($projects, 'customer', $customers)->id, 'user_id' => null, 'referenced_record_module_id' => $customers->id, 'referenced_record_id' => $customer->id]);

        $errors = $this->validationErrors(fn () => app(DeleteRecord::class)->execute($this->contextFor(), 'customers', $customer->id));

        $this->assertSame(['record' => 'このレコードはほかのデータから参照されているため削除できません'], $errors);
        $this->assertModelExists($customer);
    }

    public function test_deletes_an_old_record_that_the_current_definition_would_reject(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title', attributes: ['is_required' => true]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);

        app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $record->id);

        $this->assertModelMissing($record);
    }

    public function test_rejects_without_the_delete_permission(): void
    {
        $module = $this->recordModule('projects', [RecordModuleAction::Read]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);

        try {
            app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $record->id);
            $this->fail('削除できた');
        } catch (AuthorizationException) {
            $this->assertModelExists($record);
        }
    }

    /**
     * 業務モジュールが無い・明細のモジュールなら 404、行の判定を通らないレコードも 404 で何も消えない(9.2 の 2 の「削除の結果」の表)。
     */
    public function test_a_missing_or_details_module_and_a_record_hidden_by_the_row_access_are_not_found(): void
    {
        $fixture = $this->rowAccessFixture();
        $recordId = $fixture['recordId'];
        $guest = $this->contextFor();
        $stranger = $this->contextFor($fixture['people']['stranger']->id);

        foreach ([['nothing', $guest], ['rows_rows', $guest], ['meetings', $stranger]] as [$code, $context]) {
            $delete = fn () => app(DeleteRecord::class)->execute($context, $code, $recordId);
            $this->assertThrows($delete, ModelNotFoundException::class);
        }

        $this->assertTrue(Record::query()->whereKey($recordId)->exists());
    }

    public function test_a_record_of_another_module_is_not_found(): void
    {
        $this->recordModule('projects');
        $other = Record::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $other->id);
    }
}
