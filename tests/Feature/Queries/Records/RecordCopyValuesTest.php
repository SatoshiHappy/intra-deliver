<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCopyChecks;
use Tests\Support\TransactionalTestCase;

/**
 * 写しの初期値の公開の口「値から」と、確かめの 1 か所(docs の 設計/業務データの入れ物/項目と規則/README.md の 5 節の「開く時の確かめ」の表と
 * 「確かめの 1 か所」、設計/ワークフロー/業務データとの接続/公開の口.md の 7 節、
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 13.3): 今日に有効でない人と組織・
 * 読めない参照先・選択肢に無いコードを空にし、空にした欄を画面の欄の順で返す。ほかの型は確かめない。判定をせず、鍵を取らず、書かない。
 *
 * @phpstan-import-type CopyCheckFixture from BuildsCopyChecks
 */
final class RecordCopyValuesTest extends TransactionalTestCase
{
    use BuildsCopyChecks;

    public function test_users_and_departments_are_kept_only_when_effective_today_keeping_kind_and_order(): void
    {
        $fixture = $this->copyCheckFixture();
        ['users' => $users, 'departments' => $departments] = $this->people();
        $party = [];

        foreach (array_keys($users) as $key) {
            $party[] = ['type' => 'user', 'id' => $users[$key]->id];
            $party[] = ['type' => 'department', 'id' => $departments[$key]->id];
        }

        $copied = $this->fromValues($fixture, [
            'user' => ['id' => $users['endsTomorrow']->id],
            'dept' => ['id' => $departments['endsToday']->id],
            'party' => $party,
        ]);

        $this->assertSame(['id' => $users['endsTomorrow']->id], self::targetsOf($copied['values']['user']));
        $this->assertNull($copied['values']['dept']);
        $this->assertSame([
            ['type' => 'user', 'id' => $users['endsTomorrow']->id],
            ['type' => 'department', 'id' => $departments['endsTomorrow']->id],
            ['type' => 'user', 'id' => $users['startsToday']->id],
            ['type' => 'department', 'id' => $departments['startsToday']->id],
        ], self::targetsOf($copied['values']['party']));
        $this->assertSame(['dept', 'party'], array_column($copied['clearedFields'], 'name'));
    }

    public function test_record_references_need_the_read_and_the_row_access_of_the_requester(): void
    {
        $fixture = $this->copyCheckFixture();
        $secretModuleId = RecordModule::query()->where('code', 'm_secret')->sole()->id;
        $other = Record::factory()->create(['record_module_id' => $secretModuleId]);
        $cases = [
            'readable' => [['id' => $fixture['refOk']->id], ['id' => $fixture['refOk']->id]],
            'hidden by the row access' => [['id' => $fixture['refHidden']->id], null],
            'missing' => [['id' => 999_999_999], null],
            'of another module' => [['id' => $other->id], null],
        ];

        foreach ($cases as $label => [$given, $expected]) {
            $copied = $this->fromValues($fixture, ['ref' => $given]);
            $this->assertSame($expected, self::targetsOf($copied['values']['ref']), $label);
            $names = array_column($copied['clearedFields'], 'name');
            $this->assertSame($expected === null ? ['ref'] : [], $names, $label);
        }

        $secret = $this->fromValues($fixture, ['secret' => ['id' => $fixture['secret']->id]]);
        $this->assertNull($secret['values']['secret'], 'read の無い業務モジュール');
    }

    public function test_choices_out_of_the_options_are_cleared_and_other_types_are_not_checked(): void
    {
        $fixture = $this->copyCheckFixture();
        $long = str_repeat('長', 2000);

        $copied = $this->fromValues($fixture, ['note' => $long, 'choice' => ['code' => 'gone', 'name' => '消した']]);
        $kept = $this->fromValues($fixture, ['choice' => ['code' => 'b', 'name' => '元の名前']]);

        $this->assertSame(['note' => $long, 'choice' => null], $copied['values']);
        $this->assertSame(['choice'], array_column($copied['clearedFields'], 'name'));
        $this->assertSame(['code' => 'b', 'name' => 'bの名称'], $kept['values']['choice']);
        $this->assertSame([], array_column($kept['clearedFields'], 'name'));
    }

    public function test_detail_rows_stay_when_their_values_are_cleared_and_names_follow_the_screen_order(): void
    {
        $fixture = $this->copyCheckFixture();
        ['users' => $users] = $this->people();
        $rows = [
            ['values' => ['who' => ['id' => $users['disabled']->id], 'item' => '1 行目']],
            ['values' => ['who' => ['id' => $users['endsToday']->id], 'item' => '2 行目']],
            ['values' => ['who' => ['id' => $users['startsToday']->id], 'item' => '3 行目']],
        ];

        $copied = $this->fromValues(
            $fixture,
            ['user' => ['id' => $users['startsTomorrow']->id], 'choice' => ['code' => 'gone', 'name' => null]],
            ['lines' => $rows],
        );

        $this->assertCount(3, $copied['details']['lines']);
        $this->assertSame(
            [null, null, ['id' => $users['startsToday']->id]],
            array_map(fn (array $row) => self::targetsOf($row['values']['who']), $copied['details']['lines']),
        );
        $items = array_column(array_column($copied['details']['lines'], 'values'), 'item');
        $this->assertSame(['1 行目', '2 行目', '3 行目'], $items);
        $this->assertSame(['choice', 'user', 'linesのwho', 'linesのwho'], array_column($copied['clearedFields'], 'name'));
        $cell = fn (array $entry) => [$entry['kind'], $entry['detail'], $entry['row'], $entry['field']];
        $this->assertSame(
            [['field', null, null, 'user'], ['cell', 'lines', 0, 'who'], ['cell', 'lines', 1, 'who']],
            array_map($cell, array_slice($copied['clearedFields'], 1)),
        );
    }

    public function test_the_target_record_cannot_be_pointed_by_its_own_values_or_its_rows(): void
    {
        $fixture = $this->copyCheckFixture();
        $targetId = Record::factory()->create(['record_module_id' => $fixture['module']->id])->id;
        $values = ['self' => ['id' => $targetId]];
        $details = ['lines' => [['values' => ['parent_ref' => ['id' => $targetId]]]]];
        $forms = app(RecordFormQuery::class);
        $date = CarbonImmutable::parse('2026-10-11');

        $requester = $this->requesterOf($fixture);

        $toTarget = $forms->copyFromValues($requester, 'm_src', $targetId, $values, $details, $date, 'ja');
        $toNew = $forms->copyFromValues($requester, 'm_src', null, $values, $details, $date, 'ja');

        $this->assertNull($toTarget['values']['self']);
        $this->assertNull($toTarget['details']['lines'][0]['values']['parent_ref']);
        $this->assertSame(['self', 'linesのparent_ref'], array_column($toTarget['clearedFields'], 'name'));
        $this->assertSame(['id' => $targetId], self::targetsOf($toNew['values']['self']));
        $this->assertSame([], array_column($toNew['clearedFields'], 'name'));
    }

    public function test_only_the_given_fields_are_returned_and_unknown_codes_are_dropped(): void
    {
        $fixture = $this->copyCheckFixture();

        $copied = $this->fromValues($fixture, ['note' => 'メモ', 'unknown' => 'x'], ['unknown_rows' => []]);

        $this->assertSame(['note' => 'メモ'], $copied['values']);
        $this->assertSame([], $copied['details']);
        $this->assertArrayNotHasKey('rowAccess', $copied);
    }

    public function test_missing_modules_and_detail_modules_are_not_found(): void
    {
        $fixture = $this->copyCheckFixture();
        $forms = app(RecordFormQuery::class);
        $date = CarbonImmutable::parse('2026-10-11');

        foreach (['missing', 'lines_rows'] as $code) {
            try {
                $forms->copyFromValues($this->requesterOf($fixture), $code, null, [], [], $date, 'ja');
                $this->fail("{$code} は 404 になる");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_copy_does_not_judge_the_module_takes_no_lock_and_writes_nothing(): void
    {
        $fixture = $this->copyCheckFixture();
        $stranger = new Requester(null, null);
        $counts = fn () => [
            Record::query()->count(), RecordReference::query()->count(), RecordRevision::query()->count(),
        ];
        $before = $counts();
        DB::enableQueryLog();

        $values = ['note' => 'メモ', 'ref' => ['id' => $fixture['refOk']->id]];
        $copied = app(RecordFormQuery::class)->copyFromValues(
            $stranger, 'm_src', null, $values, [], CarbonImmutable::parse('2026-10-11'), 'ja',
        );

        $this->assertSame('メモ', $copied['values']['note']);
        $this->assertNull($copied['values']['ref'], 'ゲストには見えない参照先は空(確かめは判定を求める者で)');
        $this->assertSame($before, $counts());
        $locks = array_filter(
            DB::getQueryLog(),
            fn (array $query) => str_contains(strtolower($query['query']), 'for key share'),
        );
        $this->assertSame([], $locks);
    }

    /**
     * @param  CopyCheckFixture  $fixture
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $details
     * @return array{values: array<string, mixed>, details: array<string, list<array{values: array<string, mixed>}>>,
     *     clearedFields: list<array{kind: string, field: string|null, detail: string|null, row: int|null,
     *     name: string}>}
     */
    private function fromValues(array $fixture, array $values, array $details = []): array
    {
        return app(RecordFormQuery::class)->copyFromValues(
            $this->requesterOf($fixture), 'm_src', null, $values, $details, CarbonImmutable::parse('2026-10-11'), 'ja',
        );
    }
}
