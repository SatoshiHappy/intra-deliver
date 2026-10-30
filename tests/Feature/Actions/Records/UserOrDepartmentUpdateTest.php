<?php

namespace Tests\Feature\Actions\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;
use Tests\Support\WritesUserOrDepartmentFields;

/**
 * 型「ユーザーと組織」の更新(docs の 設計/業務データの入れ物/項目と規則/README.md 5 節、規則と業務処理/README.md 9.1): 今の値に無い相手
 * だけを確かめ直し、同じ id の種類だけを変えると相手が替わり、値を変えない更新は参照の行に触れない。書き込みの形の規則は
 * {@see UserOrDepartmentWriteTest}。
 */
final class UserOrDepartmentUpdateTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;
    use WritesUserOrDepartmentFields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartyFields();
    }

    public function test_an_update_checks_only_targets_that_are_not_in_the_current_value(): void
    {
        $record = Record::factory()->create(['record_module_id' => $this->fields['parties']->record_module_id]);
        $closed = $this->department($this->set, [['2020-01-01', '2026-04-01']]);
        $this->refer($record, 'parties', ['department_id' => $closed->id]);
        $this->refer($record, 'host', ['department_id' => $closed->id]);
        $added = $this->user();
        $another = $this->user(self::RETIRED);

        $kept = [
            'parties' => [self::partyInputOf($added), self::partyInputOf($closed)],
            'host' => self::partyInputOf($closed),
        ];
        $this->update($record, $kept);
        $input = ['parties' => [self::partyInputOf($added), self::partyInputOf($closed), self::partyInputOf($another)]];
        $errors = $this->validationErrors(fn () => $this->update($record, $input));

        $this->assertSame([['user', $added->id, 0], ['department', $closed->id, 1]], $this->rows($record, 'parties'));
        $this->assertSame([['department', $closed->id, 0]], $this->rows($record, 'host'));
        $this->assertSame(['values.parties.2' => 'partiesの値が正しくありません'], $errors);
    }

    public function test_an_update_that_changes_only_the_kind_of_the_same_id_writes_the_other_target(): void
    {
        [$user, $department] = $this->userAndDepartmentWithTheSameId($this->set);
        $record = $this->create(['host' => self::partyInputOf($user), 'parties' => [self::partyInputOf($user)]]);

        $this->update($record, [
            'host' => self::partyInputOf($department),
            'parties' => [self::partyInputOf($department)],
        ]);

        $this->assertSame([['department', $department->id, 0]], $this->rows($record, 'host'));
        $this->assertSame([['department', $department->id, 0]], $this->rows($record, 'parties'));
    }

    public function test_an_update_that_keeps_the_value_does_not_touch_the_rows(): void
    {
        [$user, $section] = [$this->user(), $this->department($this->set)];
        $value = [
            'host' => self::partyInputOf($section),
            'parties' => [self::partyInputOf($user), self::partyInputOf($section)],
        ];
        $record = $this->create($value);
        $before = RecordReference::query()->where('record_id', $record->id)->orderBy('id')->pluck('id')->all();

        $this->update($record, [...$value, 'title' => '変更']);
        $this->update($record, ['title' => '再変更']);

        $after = RecordReference::query()->where('record_id', $record->id)->orderBy('id')->pluck('id')->all();
        $this->assertSame($before, $after);
    }

    /**
     * @param  array<string, int>  $target  相手の列(user_id か department_id)
     */
    private function refer(Record $record, string $code, array $target): void
    {
        $field = $this->fields[$code];
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => null,
            'is_multiple' => $field->refresh()->is_multiple,
            ...$target,
        ]);
    }
}
