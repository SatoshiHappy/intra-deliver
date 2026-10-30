<?php

namespace Tests\Feature\Models\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordReference;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 参照の項目の値(docs の 設計/業務データの入れ物/データモデル/表.md 2.6・制約の分担と検査.md 7 節: 持ち主のレコードと項目が同じ業務モジュールにある、参照先のレコードが
 * 項目の定義した参照先の業務モジュールにある(複合外部キー)、1 行は 1 つの相手だけを指す、複数でない項目に参照は 1 つ、複数選べる参照は
 * 同じ相手・同じ並びの位置を 2 度持たず、is_multiple の写しが項目と同じ。型「ユーザーと組織」の項目は同じ id のユーザーと組織を別の相手として持つ)。
 */
final class RecordReferenceTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const ONE_TARGET_CHECK = 'record_references_referenced_record_id_user_id_department_id_referenced_record_module_id_check';

    public function test_accepts_a_reference_to_each_kind_of_target(): void
    {
        $user = RecordReference::factory()->create()->refresh();
        $department = RecordReference::factory()->forDepartment()->create()->refresh();
        $record = RecordReference::factory()->forRecord()->create()->refresh();

        $this->assertInstanceOf(User::class, $user->user);
        $this->assertInstanceOf(Department::class, $department->department);
        $this->assertInstanceOf(Record::class, $record->referencedRecord);
    }

    public function test_rejects_a_referenced_record_outside_the_module_the_field_refers_to(): void
    {
        $recordOfAnotherModule = Record::factory()->create();

        $this->assertRejectedBy(
            'record_references_record_field_id_referenced_record_module_id_foreign',
            fn () => RecordReference::factory()->forRecord()->create([
                'referenced_record_module_id' => $recordOfAnotherModule->record_module_id,
                'referenced_record_id' => $recordOfAnotherModule->id,
            ]),
        );
    }

    public function test_rejects_a_field_of_another_module(): void
    {
        $this->assertRejectedBy(
            'record_references_record_module_id_record_field_id_foreign',
            fn () => RecordReference::factory()->create(['record_field_id' => RecordField::factory()->state(['type' => RecordFieldType::User])]),
        );
    }

    public function test_rejects_a_record_module_other_than_the_owner_records(): void
    {
        $recordOfAnotherModule = Record::factory()->create();

        $this->assertRejectedBy(
            'record_references_record_module_id_record_id_foreign',
            fn () => RecordReference::factory()->create(['record_id' => $recordOfAnotherModule->id]),
        );
    }

    public function test_rejects_two_targets_or_no_target(): void
    {
        $this->assertRejectedBy(self::ONE_TARGET_CHECK, fn () => RecordReference::factory()->create(['department_id' => Department::factory()]));
        $this->assertRejectedBy(self::ONE_TARGET_CHECK, fn () => RecordReference::factory()->create(['user_id' => null]));
    }

    public function test_rejects_a_referenced_record_without_its_module(): void
    {
        $referenced = Record::factory()->create();

        $this->assertRejectedBy(
            self::ONE_TARGET_CHECK,
            fn () => RecordReference::factory()->forRecord()->create(['referenced_record_module_id' => null, 'referenced_record_id' => $referenced->id]),
        );
    }

    public function test_rejects_a_second_reference_for_the_same_field_of_a_record(): void
    {
        $reference = RecordReference::factory()->create();

        $this->assertRejectedBy(
            'record_references_record_id_record_field_id_sort_key_unique',
            fn () => RecordReference::factory()->create($reference->only(['record_module_id', 'record_id', 'record_field_id'])),
        );
    }

    public function test_a_multiple_field_accepts_other_targets_and_rejects_the_same_target_or_position_twice(): void
    {
        $owner = [...$this->multipleOwner(RecordFieldType::User), 'is_multiple' => true];
        $first = RecordReference::factory()->create($owner);

        $second = RecordReference::factory()->create([...$owner, 'sort_key' => 1])->refresh();

        $this->assertSame([1, true], [$second->sort_key, $second->is_multiple]);
        $this->assertRejectedBy(
            'record_references_record_id_record_field_id_user_id_unique',
            fn () => RecordReference::factory()->create([...$owner, 'sort_key' => 2, 'user_id' => $first->user_id]),
        );
        $this->assertRejectedBy(
            'record_references_record_id_record_field_id_sort_key_unique',
            fn () => RecordReference::factory()->create([...$owner, 'sort_key' => 1]),
        );
    }

    public function test_a_multiple_department_field_rejects_the_same_department_twice(): void
    {
        $owner = [...$this->multipleOwner(RecordFieldType::Department), 'is_multiple' => true, 'user_id' => null];
        $first = RecordReference::factory()->create([...$owner, 'department_id' => Department::factory()]);

        RecordReference::factory()->create([...$owner, 'sort_key' => 1, 'department_id' => Department::factory()]);

        $this->assertRejectedBy(
            'record_references_record_id_record_field_id_department_id_unique',
            fn () => RecordReference::factory()->create(
                [...$owner, 'sort_key' => 2, 'department_id' => $first->department_id],
            ),
        );
    }

    public function test_a_user_or_department_field_holds_a_user_and_a_department_of_the_same_id(): void
    {
        $owner = [...$this->multipleOwner(RecordFieldType::UserOrDepartment), 'is_multiple' => true];
        // 採番の続きと重ならないように、両方の表の最大より十分大きい id にする
        $id = max((int) User::query()->max('id'), (int) Department::query()->max('id')) + 100_000;
        User::factory()->create(['id' => $id]);
        Department::factory()->create(['id' => $id]);

        RecordReference::factory()->create([...$owner, 'user_id' => $id]);
        $asDepartment = [...$owner, 'user_id' => null, 'department_id' => $id];
        $department = RecordReference::factory()->create([...$asDepartment, 'sort_key' => 1]);

        $this->assertSame([null, $id], [$department->refresh()->user_id, $department->department_id]);
        $this->assertRejectedBy(
            'record_references_record_id_record_field_id_user_id_unique',
            fn () => RecordReference::factory()->create([...$owner, 'sort_key' => 2, 'user_id' => $id]),
        );
        $this->assertRejectedBy(
            'record_references_record_id_record_field_id_department_id_unique',
            fn () => RecordReference::factory()->create([...$asDepartment, 'sort_key' => 2]),
        );
    }

    public function test_rejects_a_position_other_than_0_for_a_single_field(): void
    {
        $this->assertRejectedBy(
            'record_references_is_multiple_sort_key_check',
            fn () => RecordReference::factory()->create(['sort_key' => 1]),
        );
    }

    public function test_rejects_a_copy_of_is_multiple_that_differs_from_the_field(): void
    {
        $foreignKey = 'record_references_record_field_id_is_multiple_foreign';
        $multipleWithoutCopy = $this->multipleOwner(RecordFieldType::User);

        $this->assertRejectedBy($foreignKey, fn () => RecordReference::factory()->create(['is_multiple' => true]));
        $this->assertRejectedBy($foreignKey, fn () => RecordReference::factory()->create($multipleWithoutCopy));
    }

    public function test_rejects_a_position_beyond_15_digits(): void
    {
        $owner = [...$this->multipleOwner(RecordFieldType::User), 'is_multiple' => true];

        $this->assertRejectedBy(
            'record_references_sort_key_check',
            fn () => RecordReference::factory()->create([...$owner, 'sort_key' => 1_000_000_000_000_000]),
        );
    }

    /**
     * 複数選べる参照の項目と、その業務モジュールのレコード(参照の行の持ち主の列)。
     *
     * @return array{record_module_id: int, record_id: int, record_field_id: int}
     */
    private function multipleOwner(RecordFieldType $type): array
    {
        $field = RecordField::factory()->create(['type' => $type, 'is_multiple' => true]);
        $record = Record::factory()->create(['record_module_id' => $field->record_module_id]);

        return [
            'record_module_id' => $field->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
        ];
    }
}
