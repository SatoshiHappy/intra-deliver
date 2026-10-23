<?php

namespace Tests\Feature\Seeders;

use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Queries\Workflow\ApplicationBaseDates;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SeedingDates;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 初期データの日付の規則(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.1・6 節): 流す日からの相対。
 * Feature テストの流す日は基準の日(phpunit.xml)で、別の流す日は TX の中で全部の表を空にして流し直して確かめる
 * (終わりの巻き戻しで元の初期データに戻る)。
 * 処理の日時は、見本が操作ごとに合わせた時計で書く列(SAMPLE_TIME_COLUMNS。時計を合わせる規則は同じ文書の 1.2)を比べる。
 */
final class SeedingDatesTest extends TransactionalTestCase
{
    /** 別の流す日(基準の日の 400 日後。見本の申請基準日が固定の日付のままなら、今日の前後 1 年を外れる日) */
    private const LATER_DAY = '2027-11-19';

    private const SHIFT_DAYS = 400;

    /** 日付を持つ表のうち、初期データが日付の値を入れるもの(比べる値が空にならないことの確かめ) */
    private const SEEDED_DATE_TABLES = [
        'company_post_terms', 'department_inclusions', 'department_memberships', 'department_terms', 'records',
        'user_roles', 'user_terms', 'workflow_cases', 'workflow_flow_versions', 'workflow_proxies',
    ];

    /** 見本の処理の日時(申請日・処理日時・完了日・到達日時・確認日時・代理の登録の日時・通知の日時 = 同じ文書の 1.2): 表 => 列 */
    private const SAMPLE_TIME_COLUMNS = [
        'workflow_cases' => ['applied_at', 'completed_at', 'updated_at'],
        'workflow_case_histories' => ['created_at'],
        'workflow_case_nodes' => ['reached_at'],
        'workflow_case_confirm_targets' => ['created_at'],
        'workflow_case_confirmations' => ['created_at'],
        'workflow_proxies' => ['created_at'],
        'notices' => ['created_at'],
    ];

    public function test_the_feature_tests_seed_on_the_base_day_so_the_written_dates_are_kept(): void
    {
        $this->assertSame(SeedingDates::BASE, config('seeding.date'));
        $this->assertSame([SeedingDates::BASE, '2024-04-01', '2026-10-05T10:15:00+09:00'], [
            SeedingDates::seedingDay()->toDateString(),
            SeedingDates::day('2024-04-01'),
            SeedingDates::dateTime('2026-10-05T10:15:00+09:00'),
        ]);
    }

    /**
     * 別の流す日で流し直すと、止まらず(固定の日付だった申請基準日が今日の前後 1 年を外れない)、どの日付と見本の処理の日時も同じ日数だけずれ、
     * 前後関係が保たれ、流した後の時計は合わせる前に戻っている。流し直しは重いので 1 回にまとめる。
     */
    public function test_on_another_seeding_day_every_date_moves_by_the_same_days_and_keeps_the_order(): void
    {
        $onBase = $this->seededDates();
        $timesOnBase = $this->sampleTimes();
        $this->reseedOn(self::LATER_DAY);

        $this->assertFalse(CarbonImmutable::hasTestNow());
        $this->assertNotContains(null, $timesOnBase['workflow_case_histories'] ?? [null]);
        $moved = fn (?string $value) => $value === null ? null : $this->moved($value);
        $movedTimes = array_map(fn (array $values) => array_map($moved, $values), $timesOnBase);
        $this->assertSame($movedTimes, $this->sampleTimes());

        $this->assertSame(self::SEEDED_DATE_TABLES, array_values(array_unique(array_map(
            fn (string $key) => explode('.', $key)[0],
            array_keys(array_filter($onBase, fn (?string $value) => $value !== null)),
        ))));
        $expected = array_map(fn (?string $value) => $value === null ? null : $this->moved($value), $onBase);
        $this->assertSame($expected, $this->seededDates());
        $this->assertOrderAroundTheSeedingDay(CarbonImmutable::parse(self::LATER_DAY, 'UTC'));
    }

    /**
     * 流す日の設定が無いときは、流した時の Asia/Tokyo の今日(UTC では前日でも東京では当日)。
     */
    #[DataProvider('clocks')]
    public function test_without_a_configured_day_the_seeding_day_is_today_in_tokyo(string $now, string $expected): void
    {
        config(['seeding.date' => null]);
        CarbonImmutable::setTestNow($now);

        $this->assertSame($expected, SeedingDates::seedingDay()->toDateString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function clocks(): array
    {
        return [
            '東京の日の始まり(UTC では前日)' => ['2026-10-14 15:00:00 UTC', '2026-10-15'],
            '東京の日の終わり' => ['2026-10-15 14:59:59 UTC', '2026-10-15'],
        ];
    }

    /**
     * 流し始めに決めた流す日は、流している間に日付が変わっても変わらない。
     */
    public function test_the_seeding_day_fixed_at_the_start_does_not_move_with_the_clock(): void
    {
        config(['seeding.date' => null]);
        CarbonImmutable::setTestNow('2026-10-15 14:59:59 UTC');
        SeedingDates::fixSeedingDay();
        CarbonImmutable::setTestNow('2026-10-15 15:00:00 UTC');

        $this->assertSame('2026-10-15', SeedingDates::seedingDay()->toDateString());
    }

    #[DataProvider('malformedDays')]
    public function test_a_malformed_seeding_day_stops_the_seeder_with_a_message(string $configured): void
    {
        config(['seeding.date' => $configured]);

        $this->assertThrows(
            fn () => $this->seed(DatabaseSeeder::class),
            InvalidArgumentException::class,
            '初期データの流す日(設定 seeding.date = 環境変数 SEED_DATE)は YYYY-MM-DD の暦の日付で書く',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedDays(): array
    {
        return ['区切りが斜線' => ['2026/10/15'], '日付でない' => ['abc'], '空文字' => [''], '暦に無い日' => ['2026-02-30']];
    }

    /**
     * 入社予定の u0015 は流す日の後に入社し、版 3 は流す日の前から有効で、見本の申請基準日はその版の期間の中で
     * 流す日の前後 1 年の中。
     */
    private function assertOrderAroundTheSeedingDay(CarbonImmutable $day): void
    {
        $u0015 = User::query()->where('code', 'u0015')->sole();
        $version3 = WorkflowFlowVersion::query()->whereRelation('workflowFlow', 'code', 'project_plan')
            ->where('number', 3)->sole();

        $this->assertTrue(UserTerm::query()->where('user_id', $u0015->id)->min('start_date') > $day->toDateString());
        $this->assertTrue($version3->start_date < $day && $version3->end_date === null);

        $cases = WorkflowCase::query()->with('workflowFlowVersion')->whereNotNull('base_date')->get();
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $version = $case->workflowFlowVersion;
            $baseDate = CarbonImmutable::parse($case->base_date?->toDateString(), 'UTC');
            $label = "{$case->case_number} の申請基準日 {$baseDate->toDateString()}";
            $this->assertNotNull($version, $label);
            $this->assertTrue($version->start_date <= $baseDate, $label);
            $this->assertTrue($version->end_date === null || $baseDate < $version->end_date, $label);
            $this->assertTrue(ApplicationBaseDates::isWithinYear($baseDate, $day), $label);
        }
    }

    /**
     * 全部の表を空にし(番号の列も 1 から)、流す日を置いて初期データを流し直す。
     */
    private function reseedOn(string $day): void
    {
        $tables = DB::table('pg_tables')->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', '<>', 'migrations')->pluck('tablename')->all();
        DB::statement('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
        config(['seeding.date' => $day]);

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * 初期データの日付の値(表.列.id → 日付は Y-m-d、日時は UTC の Y-m-d H:i:s)。date の列のすべてと、
     * レコードの日付・日時の項目。
     *
     * @return array<string, string|null>
     */
    private function seededDates(): array
    {
        $dates = [];
        $columns = DB::select("select table_name, column_name from information_schema.columns
            where table_schema = current_schema() and data_type = 'date'");

        foreach ($columns as $column) {
            $values = DB::table($column->table_name)->orderBy('id')->pluck($column->column_name, 'id');

            foreach ($values as $id => $value) {
                $dates["{$column->table_name}.{$column->column_name}.{$id}"] = $value;
            }
        }

        $fieldValues = DB::select("select r.id, f.code, f.type, r.field_values ->> f.id::text as value
            from records r join record_fields f on f.record_module_id = r.record_module_id
            where f.type in ('date', 'datetime') order by r.id, f.id");

        foreach ($fieldValues as $row) {
            $dates["records.{$row->code}.{$row->id}"] = $row->value === null || $row->type === 'date' ? $row->value
                : CarbonImmutable::parse($row->value)->utc()->format('Y-m-d H:i:s');
        }

        ksort($dates);

        return $dates;
    }

    /**
     * 見本の処理の日時(表 => 列.id => UTC の Y-m-d H:i:s)。
     *
     * @return array<string, array<string, string|null>>
     */
    private function sampleTimes(): array
    {
        $times = [];

        foreach (self::SAMPLE_TIME_COLUMNS as $table => $columns) {
            $times[$table] = $this->timesOf($table, $columns);
        }

        return $times;
    }

    /**
     * 表の日時の列の値(列.id => UTC の Y-m-d H:i:s)。
     *
     * @param  list<string>  $columns
     * @return array<string, string|null>
     */
    private function timesOf(string $table, array $columns): array
    {
        $times = [];

        foreach (DB::table($table)->orderBy('id')->get(['id', ...$columns]) as $row) {
            foreach ($columns as $column) {
                $value = $row->{$column};
                $utc = is_string($value) ? CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s') : null;
                $times["{$column}.{$row->id}"] = $utc;
            }
        }

        return $times;
    }

    /**
     * 日付・日時(UTC)を SHIFT_DAYS 日後にする。
     */
    private function moved(string $value): string
    {
        $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';

        return CarbonImmutable::parse($value, 'UTC')->addDays(self::SHIFT_DAYS)->format($format);
    }
}
