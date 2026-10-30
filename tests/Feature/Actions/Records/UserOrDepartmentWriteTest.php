<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;
use Tests\Support\WritesUserOrDepartmentFields;

/**
 * 型「ユーザーと組織」の書き込み(docs の 設計/業務データの入れ物/項目と規則/README.md 2 節の表・5 節、規則と業務処理/README.md 8.2・9.1、
 * 画面と検査.md 13 節の「型「ユーザーと組織」」): 1 つと複数でユーザーと組織が混ざって入り(相手の種類で user_id か department_id)、相手の形・数・
 * 重なり・有効でない相手が 422(キーは今の参照と同じ)、同じ id のユーザーと組織は別の相手。更新で変えない相手を確かめ直さないことは
 * {@see UserOrDepartmentUpdateTest}。
 */
final class UserOrDepartmentWriteTest extends TransactionalTestCase
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

    public function test_a_single_field_holds_a_user_or_a_department(): void
    {
        [$user, $department] = [$this->user(), $this->department($this->set)];

        $toUser = $this->create(['host' => self::partyInputOf($user)]);
        $toDepartment = $this->create(['host' => ['id' => $department->id, 'type' => 'department']]);
        $empty = $this->create(['host' => null]);

        $this->assertSame([['user', $user->id, 0]], $this->rows($toUser, 'host'));
        $this->assertSame([['department', $department->id, 0]], $this->rows($toDepartment, 'host'));
        $this->assertSame([], $this->rows($empty, 'host'));
    }

    public function test_a_multiple_field_holds_users_and_departments_mixed_in_the_input_order(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $section = $this->department($this->set);

        $record = $this->create([
            'parties' => [self::partyInputOf($b), self::partyInputOf($section), self::partyInputOf($a)],
        ]);

        $this->assertSame(
            [['user', $b->id, 0], ['department', $section->id, 1], ['user', $a->id, 2]],
            $this->rows($record, 'parties'),
        );
    }

    public function test_a_user_and_a_department_of_the_same_id_are_different_targets(): void
    {
        [$user, $department] = $this->userAndDepartmentWithTheSameId($this->set);

        $record = $this->create(['parties' => [self::partyInputOf($user), self::partyInputOf($department)]]);

        $this->assertSame($user->id, $department->id);
        $this->assertSame(
            [['user', $user->id, 0], ['department', $department->id, 1]],
            $this->rows($record, 'parties'),
        );
    }

    public function test_rejects_a_value_that_is_not_a_target_with_the_field_key(): void
    {
        $user = $this->user();
        $wrong = fn (string $code) => ["values.{$code}" => "{$code}の値が正しくありません"];
        $notTargets = [
            ['type' => 'role', 'id' => $user->id],
            ['type' => 'user', 'id' => (string) $user->id],
            ['type' => 'user', 'id' => 1.0],
            ['type' => 'user'],
            ['type' => 'user', 'id' => $user->id, 'label' => '山田'],
            $user->id,
            [$user->id],
        ];

        foreach ($notTargets as $value) {
            $this->assertSame($wrong('host'), $this->validationErrors(fn () => $this->create(['host' => $value])));
            $this->assertSame(
                $wrong('parties'),
                $this->validationErrors(fn () => $this->create(['parties' => [self::partyInputOf($user), $value]])),
            );
        }
        $notArray = $this->validationErrors(fn () => $this->create(['parties' => self::partyInputOf($user)]));
        $this->assertSame($wrong('parties'), $notArray);
    }

    public function test_the_number_of_targets_is_0_to_100(): void
    {
        $users = array_map(fn () => $this->user(), range(1, 100));
        $parties = array_map(self::partyInputOf(...), $users);

        $none = $this->create(['parties' => []]);
        $one = $this->create(['parties' => [$parties[0]]]);
        $hundred = $this->create(['parties' => $parties]);
        $tooMany = [...$parties, self::partyInputOf($this->department($this->set))];
        $errors = $this->validationErrors(fn () => $this->create(['parties' => $tooMany]));

        $this->assertSame([], $this->rows($none, 'parties'));
        $this->assertCount(1, $this->rows($one, 'parties'));
        $this->assertCount(100, $this->rows($hundred, 'parties'));
        $this->assertSame(['values.parties' => 'partiesは 100 件までにしてください'], $errors);
    }

    public function test_rejects_the_same_target_twice_even_with_the_keys_in_another_order(): void
    {
        $section = $this->department($this->set);
        $value = [self::partyInputOf($section), ['id' => $section->id, 'type' => 'department']];

        $errors = $this->validationErrors(fn () => $this->create(['parties' => $value]));

        $this->assertSame(['values.parties' => 'partiesに同じ値が重複しています'], $errors);
    }

    public function test_a_wrong_shape_is_rejected_without_looking_up_the_targets(): void
    {
        $user = $this->user();
        $wrong = [
            ['parties' => array_map(fn (int $id) => ['type' => 'user', 'id' => $id], range(1, 101))],
            ['parties' => [self::partyInputOf($user), self::partyInputOf($user)]],
            ['parties' => [self::partyInputOf($user), ['type' => 'role', 'id' => 1]]],
        ];

        foreach ($wrong as $values) {
            $errors = [];
            $lookups = $this->referenceTargetLookups(function () use ($values, &$errors) {
                $errors = $this->validationErrors(fn () => $this->create($values));
            });

            $this->assertSame([], $lookups);
            $this->assertCount(1, $errors);
        }
    }

    public function test_rejects_targets_not_effective_on_the_date_with_the_index_key(): void
    {
        $retired = $this->user(self::RETIRED);
        $closed = $this->department($this->set, [['2020-01-01', '2026-04-01']]);
        $wrong = 'partiesの値が正しくありません';

        $errors = $this->validationErrors(fn () => $this->create(['parties' => [
            self::partyInputOf($this->user()),
            self::partyInputOf($retired),
            self::partyInputOf($closed),
            ['type' => 'department', 'id' => 999_999_999],
        ]]));
        $single = $this->validationErrors(fn () => $this->create(['host' => self::partyInputOf($closed)]));

        $this->assertSame(
            ['values.parties.1' => $wrong, 'values.parties.2' => $wrong, 'values.parties.3' => $wrong],
            $errors,
        );
        $this->assertSame(['values.host' => 'hostの値が正しくありません'], $single);
    }

    public function test_a_field_of_a_detail_row_follows_the_same_rules_and_keys(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $this->field($this->childOf($tasks), 'owners', RecordFieldType::UserOrDepartment, ['is_multiple' => true]);
        [$user, $section] = [$this->user(), $this->department($this->set)];
        $row = fn (array $parties) => ['tasks' => [new RecordDetailRowInput(null, ['owners' => $parties])]];
        $create = fn (array $parties) => app(CreateRecord::class)
            ->execute($this->contextFor(), 'projects', new RecordInput([], $row($parties)));

        $repeated = $this->validationErrors(fn () => $create([self::partyInputOf($user), self::partyInputOf($user)]));
        $retired = $this->user(self::RETIRED);
        $invalid = $this->validationErrors(fn () => $create([self::partyInputOf($user), self::partyInputOf($retired)]));
        $id = $create([self::partyInputOf($section), self::partyInputOf($user)]);

        $this->assertSame(['details.tasks.0.values.owners' => 'ownersに同じ値が重複しています'], $repeated);
        $this->assertSame(['details.tasks.0.values.owners.1' => 'ownersの値が正しくありません'], $invalid);
        $child = Record::query()->where('parent_record_id', $id)->sole();
        $this->assertSame([[null, $section->id], [$user->id, null]], RecordReference::query()
            ->where('record_id', $child->id)->orderBy('sort_key')->get()
            ->map(fn (RecordReference $reference) => [$reference->user_id, $reference->department_id])->all());
    }

    public function test_a_required_field_reads_no_targets_as_empty(): void
    {
        $this->fields['parties']->update(['is_required' => true]);
        $user = $this->user();

        $errors = $this->validationErrors(fn () => $this->create(['parties' => []]));
        $record = $this->create(['parties' => [self::partyInputOf($user)]]);

        $this->assertSame(['values.parties' => 'partiesを入力してください'], $errors);
        $this->assertSame([['user', $user->id, 0]], $this->rows($record, 'parties'));
    }

    /**
     * 処理の中で走った、ユーザー・組織の表を読む問い合わせ(参照の相手を確かめる問い合わせ)。
     *
     * @return list<string>
     */
    private function referenceTargetLookups(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $isLookup = fn (string $query) => preg_match('/from "(users|departments)"/', $query) === 1;

        return array_values(array_filter($queries, $isLookup));
    }
}
