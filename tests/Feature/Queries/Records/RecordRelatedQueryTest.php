<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordRelatedColumn;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRelatedEntry;
use App\Queries\Records\Api\RecordRelatedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRelatedRecords;
use Tests\Support\RowAccessRestriction;
use Tests\Support\TransactionalTestCase;

/**
 * 関連レコードの読み取りの口(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 1〜4 節・6 節・7 節・9 節の「行の権限」「入口」「列」「新規」):
 * 入口は定義から作り(0 件も出る)、読めない業務モジュールの入口は名前も件数も出さない。件数・表は行の判定を通る参照元だけで、明細の 2 行が同じ親でも 1 件。
 * 「新規」は 4.1 節の条件の組。問い合わせの数は参照元の数に比例しない。フォームの参照制限の初期値 rowAccess = 参照元の業務モジュールの
 * 参照制限の既定と空の参照者(4.2 節)。
 *
 * @phpstan-import-type RelatedFixture from BuildsRelatedRecords
 */
final class RecordRelatedQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRelatedRecords;

    private const ALL_BUT_CREATE = [RecordModuleAction::Read, RecordModuleAction::Update, RecordModuleAction::Delete];

    public function test_entries_come_from_the_definition_in_order_with_names_and_counts_of_distinct_parents(): void
    {
        $f = $this->relatedFixture();
        $project = $this->recordIn($f['projects'], ['title' => '案件 A']);
        $this->refer($this->recordIn($f['estimates']), $f['customer'], $project);
        $this->refer($this->recordIn($f['estimates']), $f['billing'], $project);
        $withLines = $this->recordIn($f['estimates']);
        $this->refer($this->detailRow($f['lines'], $withLines), $f['product'], $project);
        $this->refer($this->detailRow($f['lines'], $withLines), $f['product'], $project);

        $this->assertSame([
            ['key' => 'estimates.customer', 'name' => 'estimates(customer)', 'count' => 1, 'canCreate' => true],
            ['key' => 'estimates.billing', 'name' => 'estimates(billing)', 'count' => 1, 'canCreate' => true],
            [
                'key' => 'estimates.lines.product', 'name' => 'estimates(linesのproduct)', 'count' => 1,
                'canCreate' => false,
            ],
            ['key' => 'issues.project', 'name' => 'issues', 'count' => 0, 'canCreate' => true],
        ], $this->entryArrays($this->guest(), $project->id));
        $this->assertSame(
            ['estimates.customer', 'estimates.billing', 'estimates.lines.product', 'issues.project'],
            array_column($this->related()->entryNames($this->guest(), $project->id, $this->today()), 'key'),
        );
    }

    public function test_entries_of_an_unreadable_module_are_left_out_and_all_unreadable_gives_none(): void
    {
        $f = $this->relatedFixture(['issues' => [RecordModuleAction::Create]]);
        $project = $this->recordIn($f['projects']);
        $this->refer($this->recordIn($f['issues']), $f['project'], $project);

        $this->assertSame(
            ['estimates.customer', 'estimates.billing', 'estimates.lines.product'],
            array_column($this->entryArrays($this->guest(), $project->id), 'key'),
        );
        $this->assertNotContains('issues.project', array_column(
            $this->related()->entryNames($this->guest(), $project->id, $this->today()),
            'key',
        ));
    }

    public function test_no_entries_when_no_referencing_module_may_be_read(): void
    {
        $f = $this->relatedFixture([
            'estimates' => [RecordModuleAction::Create],
            'issues' => [RecordModuleAction::Create],
        ]);
        $project = $this->recordIn($f['projects']);
        $this->refer($this->recordIn($f['issues']), $f['project'], $project);

        $this->assertSame([], $this->entryArrays($this->guest(), $project->id));
        $this->assertSame([], $this->related()->entryNames($this->guest(), $project->id, $this->today()));
    }

    public function test_the_new_button_needs_the_referencing_create_and_this_modules_read_and_row(): void
    {
        $f = $this->relatedFixture(['estimates' => self::ALL_BUT_CREATE]);
        $project = $this->recordIn($f['projects']);

        $this->assertSame(
            [
                'estimates.customer' => false, 'estimates.billing' => false, 'estimates.lines.product' => false,
                'issues.project' => true,
            ],
            array_column($this->entryArrays($this->guest(), $project->id), 'canCreate', 'key'),
        );
        $this->assertNull($this->formOf($project->id, 'estimates.customer'));
        $this->assertNotNull($this->formOf($project->id, 'issues.project'));
    }

    public function test_the_new_button_is_hidden_without_the_read_of_this_module(): void
    {
        $f = $this->relatedFixture(['projects' => [RecordModuleAction::Create]]);
        $project = $this->recordIn($f['projects']);

        $this->assertSame([false, false, false, false], $this->canCreates($this->guest(), $project->id));
        $this->assertNull($this->formOf($project->id, 'issues.project'));
    }

    public function test_the_new_button_is_hidden_when_this_record_does_not_pass_the_row_check(): void
    {
        $f = $this->relatedFixture();
        RowAccessRestriction::restrict([$f['projects']->id]);
        $owner = $this->user();
        $project = $this->recordIn($f['projects'], [], ['created_by_user_id' => $owner->id]);

        $this->assertSame([false, false, false, false], $this->canCreates($this->guest(), $project->id));
        $this->assertSame([true, true, false, true], $this->canCreates(new Requester($owner->id, null), $project->id));
    }

    public function test_unrelated_referencing_records_are_not_counted_or_listed_unless_all_may_be_read(): void
    {
        $f = $this->relatedFixture();
        RowAccessRestriction::restrict([$f['estimates']->id]);
        $creator = $this->user();
        $project = $this->recordIn($f['projects']);
        $own = $this->recordIn($f['estimates'], [], ['created_by_user_id' => $creator->id]);
        $this->refer($own, $f['customer'], $project);
        $this->refer($this->recordIn($f['estimates']), $f['customer'], $project);
        $creatorSees = new Requester($creator->id, null);

        $this->assertSame(1, $this->entryArrays($creatorSees, $project->id)[0]['count']);
        $this->assertSame([$own->id], array_column(
            $this->related()->rows($creatorSees, $project->id, 'estimates.customer', 1, $this->today(), 'ja')['rows'],
            'id',
        ));
        $this->assertSame(0, $this->entryArrays($this->guest(), $project->id)[0]['count']);
    }

    public function test_all_referencing_records_count_with_the_read_all_permission(): void
    {
        $f = $this->relatedFixture(['estimates' => [RecordModuleAction::Read, RecordModuleAction::ReadAll]]);
        RowAccessRestriction::restrict([$f['estimates']->id]);
        $project = $this->recordIn($f['projects']);
        $unrelated = $this->recordIn($f['estimates'], [], ['created_by_user_id' => $this->user()->id]);
        $this->refer($unrelated, $f['customer'], $project);
        $this->refer($this->recordIn($f['estimates']), $f['customer'], $project);

        $this->assertSame(2, $this->entryArrays($this->guest(), $project->id)[0]['count']);
        $rows = $this->related()->rows($this->guest(), $project->id, 'estimates.customer', 1, $this->today(), 'ja');
        $this->assertCount(2, $rows['rows']);
    }

    public function test_rows_are_newest_first_twenty_a_page_with_the_listed_columns(): void
    {
        $f = $this->relatedFixture();
        $project = $this->recordIn($f['projects']);
        $ids = [];
        $titles = [];

        foreach (range(1, 21) as $number) {
            $estimate = $this->recordIn($f['estimates'], ['title' => "見積 {$number}"], [
                'updated_at' => CarbonImmutable::parse('2026-10-01')->addDays($number % 3),
            ]);
            $this->refer($estimate, $f['customer'], $project);
            $ids[] = [$number % 3, $estimate->id];
            $titles[$estimate->id] = "見積 {$number}";
        }

        // 更新日時の降順、次に id の降順
        rsort($ids);
        $first = $this->related()->rows($this->guest(), $project->id, 'estimates.customer', 1, $this->today(), 'ja');
        $second = $this->related()->rows($this->guest(), $project->id, 'estimates.customer', 2, $this->today(), 'ja');

        $this->assertSame('estimates.customer', $first['entry']);
        $this->assertSame(['code' => 'estimates', 'name' => 'estimates'], $first['module']);
        $this->assertSame(['title'], array_column($first['columns'], 'code'));
        $this->assertSame(array_column(array_slice($ids, 0, 20), 1), array_column($first['rows'], 'id'));
        $this->assertSame(array_column(array_slice($ids, 20), 1), array_column($second['rows'], 'id'));
        $this->assertSame([1, 2, 21], [$first['currentPage'], $first['lastPage'], $first['total']]);

        $beyond = $this->related()->rows($this->guest(), $project->id, 'estimates.customer', 99, $this->today(), 'ja');
        $this->assertSame([2, 2, 21], [$beyond['currentPage'], $beyond['lastPage'], $beyond['total']]);
        $this->assertSame(array_column($second['rows'], 'id'), array_column($beyond['rows'], 'id'));
        $this->assertSame(['title' => $titles[$ids[0][1]]], $first['rows'][0]['values']);
    }

    public function test_unselected_columns_fall_back_to_the_listed_the_title_or_none_and_selected_ones_win(): void
    {
        $f = $this->relatedFixture();
        $project = $this->recordIn($f['projects']);
        $this->refer($this->recordIn($f['issues']), $f['project'], $project);
        $columns = fn () => array_column(
            $this->related()->rows($this->guest(), $project->id, 'issues.project', 1, $this->today(), 'ja')['columns'],
            'code',
        );
        $this->assertSame([], $columns());

        $this->field($f['issues'], 'subject', RecordFieldType::String, ['is_title' => true, 'sort_key' => 90]);
        $this->assertSame(['subject'], $columns());

        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $index => $code) {
            $this->field($f['issues'], $code, RecordFieldType::String, ['is_listed' => true, 'sort_key' => $index + 1]);
        }

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $columns());

        foreach (['f' => 1, 'subject' => 2] as $code => $sortKey) {
            RecordRelatedColumn::factory()->create([
                'record_field_id' => $f['project']->id,
                'record_module_id' => $f['issues']->id,
                'column_record_field_id' => $f['issues']->recordFields()->where('code', $code)->sole()->id,
                'sort_key' => $sortKey,
            ]);
        }

        $this->assertSame(['f', 'subject'], $columns());
    }

    public function test_rows_of_an_unknown_or_unreadable_entry_are_not_found(): void
    {
        $f = $this->relatedFixture(['issues' => [RecordModuleAction::Create]]);
        $project = $this->recordIn($f['projects']);

        foreach (['issues.project', 'estimates.nothing', 'nothing'] as $key) {
            try {
                $this->related()->rows($this->guest(), $project->id, $key, 1, $this->today(), 'ja');
                $this->fail("{$key} が見つかった");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_form_carries_the_definition_the_headings_and_the_fixed_value_of_this_record(): void
    {
        $f = $this->relatedFixture();
        $project = $this->recordIn($f['projects'], ['title' => '案件 A']);

        $form = $this->formOf($project->id, 'estimates.customer');

        $this->assertNotNull($form);
        $this->assertSame(['estimates.customer', 'customer'], [$form['entry'], $form['field']]);
        $value = ['id' => $project->id, 'code' => null, 'label' => '案件 A', 'affiliation' => null];
        $this->assertSame($value, $form['value']);
        $this->assertSame('estimates', $form['module']['code']);
        $this->assertSame(['title', 'customer', 'billing'], $form['headings'][0]['fieldCodes']);
        $this->assertSame(['restricted' => false, 'viewers' => []], $form['rowAccess']);
        $f['estimates']->update(['row_access_restricted_by_default' => true]);
        $this->assertTrue($this->formOf($project->id, 'estimates.customer')['rowAccess']['restricted'] ?? null);
        $this->assertNull($this->formOf($project->id, 'estimates.lines.product'));
        $this->assertNull($this->formOf($project->id, 'estimates.nothing'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_referencing_records(): void
    {
        $f = $this->relatedFixture();
        $project = $this->recordIn($f['projects']);
        $count = function () use ($project): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->related()->entries($this->guest(), $project->id, $this->today());
            $this->related()->rows($this->guest(), $project->id, 'estimates.customer', 1, $this->today(), 'ja');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $this->refer($this->recordIn($f['estimates']), $f['customer'], $project);
        $one = $count();

        foreach (range(1, 5) as $ignored) {
            $this->refer($this->recordIn($f['estimates']), $f['customer'], $project);
            $this->refer($this->detailRow($f['lines'], $this->recordIn($f['estimates'])), $f['product'], $project);
        }

        $this->assertSame($one, $count());
    }

    /**
     * @return list<array{key: string, name: string, count: int, canCreate: bool}>
     */
    private function entryArrays(Requester $requester, int $recordId): array
    {
        return array_map(
            fn (RecordRelatedEntry $entry) => $entry->toArray(),
            $this->related()->entries($requester, $recordId, $this->today()),
        );
    }

    /**
     * @return list<bool> 入口ごとの「新規」(入口の並び)
     */
    private function canCreates(Requester $requester, int $recordId): array
    {
        return array_column($this->entryArrays($requester, $recordId), 'canCreate');
    }

    /**
     * ゲストの「新規」のダイアログのフォーム。
     *
     * @return array<string, mixed>|null
     */
    private function formOf(int $recordId, string $entryKey): ?array
    {
        return $this->related()->form($this->guest(), $recordId, $entryKey, $this->today(), 'ja');
    }

    private function guest(): Requester
    {
        return new Requester(null, null);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-11');
    }

    private function related(): RecordRelatedQuery
    {
        return app(RecordRelatedQuery::class);
    }
}
