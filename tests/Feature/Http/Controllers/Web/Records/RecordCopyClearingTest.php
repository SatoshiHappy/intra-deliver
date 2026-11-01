<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsCopyChecks;
use Tests\Support\TransactionalTestCase;

/**
 * 業務データの画面の複製の、開く時の確かめ(docs の 設計/業務データの入れ物/レコードの共通機能/複製.md の 2 節・5 節の反映 =
 * 設計/ワークフロー/申請の周辺/既存の設計への反映/共通機能とほかの文書.md の 4 節): 今は使えない値と参照者を空にし、copySource.clearedFields に
 * 空にした項目の名前を画面の欄の順で返す(参照者は最後に「参照制限の参照者」)。残した値はそのまま登録でき、空にした値を戻すと保存の確かめで 422。
 *
 * @phpstan-import-type CopyCheckFixture from BuildsCopyChecks
 */
final class RecordCopyClearingTest extends TransactionalTestCase
{
    use BuildsCopyChecks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_copy_clears_values_and_viewers_unusable_today_and_names_them(): void
    {
        $fixture = $this->copyCheckFixture();
        ['users' => $users, 'departments' => $departments] = $this->people();
        $record = $this->recordWith($fixture, ['choice' => 'gone', 'note' => 'メモ']);
        $this->referTo($record, 'user', $users['endsToday']);
        $this->referTo($record, 'party', $departments['disabled'], 0);
        $this->referTo($record, 'party', $users['startsToday'], 1);
        $this->referTo($record, 'ref', $fixture['refHidden']);
        $row = Record::factory()->create([
            'record_module_id' => $fixture['lines']->child_record_module_id,
            'parent_record_module_id' => $fixture['module']->id,
            'parent_record_id' => $record->id,
        ]);
        $this->referTo($row, 'who', $users['endsToday']);
        $this->restrictTo($record, [$users['endsTomorrow'], $departments['endsToday']]);
        $this->actingAs($fixture['me']);
        $party = [['type' => 'user', 'id' => $users['startsToday']->id]];
        $viewers = [['type' => 'user', 'id' => $users['endsTomorrow']->id]];

        $this->get("/records/data/m_src/create?copy={$record->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('copySource.values.note', 'メモ')
                ->where('copySource.values.choice', null)
                ->where('copySource.values.user', null)
                ->where('copySource.values.ref', null)
                ->where('copySource.values.party', fn ($value) => self::targetsOf($value) === $party)
                ->where('copySource.details.lines.0.values.who', null)
                ->where('copySource.rowAccess.restricted', true)
                ->where('copySource.rowAccess.viewers', fn ($value) => self::targetsOf($value) === $viewers)
                ->where('copySource.clearedFields', ['choice', 'user', 'party', 'ref', 'linesのwho', '参照制限の参照者']));
    }

    public function test_a_copy_without_unusable_values_has_no_cleared_fields(): void
    {
        $fixture = $this->copyCheckFixture();
        $record = $this->recordWith($fixture, ['choice' => 'a']);
        $this->referTo($record, 'ref', $fixture['refOk']);
        $this->actingAs($fixture['me']);

        $this->get("/records/data/m_src/create?copy={$record->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('copySource.values.choice', ['code' => 'a', 'name' => 'aの名称'])
                ->where('copySource.clearedFields', []));
    }

    public function test_kept_values_save_and_cleared_values_put_back_are_rejected_by_the_save(): void
    {
        $fixture = $this->copyCheckFixture();
        ['users' => $users, 'departments' => $departments] = $this->people();
        $cases = [
            'user' => [$users['endsTomorrow']->id, $users['endsToday']->id],
            'dept' => [$departments['startsToday']->id, $departments['startsTomorrow']->id],
            'ref' => [$fixture['refOk']->id, $fixture['refHidden']->id],
            'secret' => [null, $fixture['secret']->id],
            'choice' => ['a', 'gone'],
        ];

        foreach ($cases as $code => [$kept, $cleared]) {
            $this->assertTrue(Record::query()->whereKey($this->create($fixture, [$code => $kept]))->exists(), $code);

            try {
                $this->create($fixture, [$code => $cleared]);
                $this->fail("{$code} を戻すと 422");
            } catch (ValidationException $exception) {
                $this->assertSame(["values.{$code}"], array_keys($exception->errors()), $code);
            }
        }
    }

    /**
     * @param  CopyCheckFixture  $fixture
     * @param  array<string, mixed>  $values  項目のコード → 保存の形の値(参照を除く)
     */
    private function recordWith(array $fixture, array $values): Record
    {
        $ids = RecordField::query()->where('record_module_id', $fixture['module']->id)->pluck('id', 'code');
        $fieldValues = [];

        foreach ($values as $code => $value) {
            $fieldValues[$ids[$code]] = $value;
        }

        return Record::factory()->create([
            'record_module_id' => $fixture['module']->id,
            'field_values' => $fieldValues,
            'created_by_user_id' => $fixture['me']->id,
        ]);
    }

    /**
     * @param  CopyCheckFixture  $fixture
     * @param  array<string, mixed>  $values
     */
    private function create(array $fixture, array $values): int
    {
        $context = new AccessContext($fixture['me']->id, null, null, CarbonImmutable::parse('2026-10-11'), 'ja');

        return app(CreateRecord::class)->execute($context, 'm_src', new RecordInput(values: $values));
    }
}
