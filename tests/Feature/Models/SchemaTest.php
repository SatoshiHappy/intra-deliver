<?php

namespace Tests\Feature\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\CommonMasterSchema;
use Tests\Support\MeetingSchema;
use Tests\Support\MenuSchema;
use Tests\Support\NoticeSchema;
use Tests\Support\RecordsCommonSchema;
use Tests\Support\RecordsSchema;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UploadSchema;
use Tests\Support\WorkflowAttachmentSchema;
use Tests\Support\WorkflowCaseHandlingSchema;
use Tests\Support\WorkflowContentTargetSchema;
use Tests\Support\WorkflowSchema;

/**
 * 共通マスタの 29 表・メニューの 7 表・業務データの入れ物の 6 表・ワークフローの 18 表の列(型・長さ・NULL 可否・既定値)と、外部キーの索引の規則(docs の
 * 設計/共通マスタ/共通の規則.md 4 節・9 節。メニューの表も同じ規則に従う = 設計/メニュー/README.md の方針 1。入れ物の表は採った規則だけ = 設計/業務データの入れ物/データモデル/ 1 節・7 節。
 * ワークフローの表も採った規則だけ = 設計/ワークフロー/データモデル/README.md 1 節、直列化と検査.md 6 節)と、通知の表(設計/ワークフロー/案件の運用/通知.md の 2 節)、
 * レコードの共通機能の 6 表とアップロードの台帳(設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2 節)。
 * ワークフローの 18 表には一時保存の添付の表を含む(設計/ワークフロー/申請の周辺/データモデル.md の 1.1 節)。会議の 3 表(設計/業務ツール/会議/README.md の 2 節)。
 */
final class SchemaTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** 表 → 列の定義 */
    private const COLUMNS = CommonMasterSchema::COLUMNS + MenuSchema::COLUMNS + RecordsSchema::COLUMNS
        + RecordsCommonSchema::COLUMNS + WorkflowSchema::COLUMNS + WorkflowCaseHandlingSchema::COLUMNS
        + NoticeSchema::COLUMNS + UploadSchema::COLUMNS + WorkflowAttachmentSchema::COLUMNS + MeetingSchema::COLUMNS;

    /** 表を作った後に足した列(表 → 列。表の末尾に付く) */
    private const COLUMNS_ADDED_LATER = CommonMasterSchema::COLUMNS_ADDED_LATER + RecordsSchema::COLUMNS_ADDED_LATER + WorkflowSchema::COLUMNS_ADDED_LATER
        + WorkflowContentTargetSchema::COLUMNS_ADDED_LATER;

    /** 表 → モデル */
    private const MODELS = CommonMasterSchema::MODELS + MenuSchema::MODELS + RecordsSchema::MODELS
        + RecordsCommonSchema::MODELS + WorkflowSchema::MODELS + WorkflowCaseHandlingSchema::MODELS
        + NoticeSchema::MODELS + UploadSchema::MODELS + WorkflowAttachmentSchema::MODELS + MeetingSchema::MODELS;

    /**
     * @return array<string, array{string}>
     */
    public static function tables(): array
    {
        return array_combine(
            array_keys(self::COLUMNS),
            array_map(fn (string $table): array => [$table], array_keys(self::COLUMNS)),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requiredColumns(): array
    {
        $cases = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach ([...$columns, ...(self::COLUMNS_ADDED_LATER[$table] ?? [])] as $column => $definition) {
                if (str_contains($definition, 'not null') && ! str_contains($definition, 'generated always')) {
                    $cases["{$table}.{$column}"] = [$table, $column];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('tables')]
    public function test_table_has_the_designed_columns(string $table): void
    {
        $expected = [
            'id' => "bigint not null default nextval('{$table}_id_seq'::regclass)",
            ...self::COLUMNS[$table],
            'created_at' => 'timestamp(0) with time zone',
            'updated_at' => 'timestamp(0) with time zone',
            ...(self::COLUMNS_ADDED_LATER[$table] ?? []),
        ];

        $actual = $this->columnDefinitions($table);

        $this->assertSame($expected, $actual);
    }

    #[DataProvider('requiredColumns')]
    public function test_rejects_null_in_a_required_column(string $table, string $column): void
    {
        $row = [...$this->validRow($table), $column => null];

        $this->assertRejectedAsNull($table, $column, fn () => DB::table($table)->insert($row));
    }

    #[DataProvider('tables')]
    public function test_accepts_a_row_made_by_the_factory(string $table): void
    {
        $row = $this->validRow($table);
        $rows = DB::table($table)->count();

        DB::table($table)->insert($row);

        $this->assertDatabaseCount($table, $rows + 1);
    }

    #[DataProvider('tables')]
    public function test_every_foreign_key_has_an_index_that_starts_with_its_columns(string $table): void
    {
        $indexes = $this->indexLeadingColumns($table);

        $uncovered = array_filter(
            $this->foreignKeyColumns($table),
            fn (array $columns): bool => ! $this->isCovered($columns, $indexes),
        );

        $this->assertSame([], array_values($uncovered), "{$table} に、外部キーの列を先頭に持つ索引の無い外部キーがある");
    }

    /**
     * 表の列の定義(列の順)。生成列(attgenerated = 's')の式は pg_attrdef にあるので、既定値でなく生成の式として書く。
     *
     * @return array<string, string>
     */
    private function columnDefinitions(string $table): array
    {
        $rows = DB::select(
            'select a.attname as name, format_type(a.atttypid, a.atttypmod) as type, a.attnotnull as not_null, a.attgenerated as generated, pg_get_expr(d.adbin, d.adrelid) as default_value
               from pg_attribute a
               left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
              where a.attrelid = ?::regclass and a.attnum > 0 and not a.attisdropped
              order by a.attnum',
            [$table],
        );

        $definitions = [];

        foreach ($rows as $row) {
            $definitions[$row->name] = $row->type.($row->not_null ? ' not null' : '').match (true) {
                $row->generated === 's' => " generated always as {$row->default_value} stored",
                $row->default_value !== null => ' default '.$row->default_value,
                default => '',
            };
        }

        return $definitions;
    }

    /**
     * factory が作る、表の正しい 1 行の値(参照先の行は factory が作る)。
     *
     * @return array<string, mixed>
     */
    private function validRow(string $table): array
    {
        return Factory::factoryForModel(self::MODELS[$table])->makeOne()->getAttributes();
    }

    /**
     * 表の外部キーごとの列番号。
     *
     * @return array<int, list<int>>
     */
    private function foreignKeyColumns(string $table): array
    {
        $rows = DB::select("select array_to_string(conkey, ' ') as columns from pg_constraint where conrelid = ?::regclass and contype = 'f'", [$table]);

        return array_map(fn (stdClass $row): array => array_map('intval', explode(' ', $row->columns)), $rows);
    }

    /**
     * 表の索引(部分索引を除く。一意制約・排他制約の索引を含む)ごとの列番号(式の列は 0)。
     *
     * @return array<int, list<int>>
     */
    private function indexLeadingColumns(string $table): array
    {
        $rows = DB::select('select indkey::text as columns from pg_index where indrelid = ?::regclass and indpred is null', [$table]);

        return array_map(fn (stdClass $row): array => array_map('intval', explode(' ', $row->columns)), $rows);
    }

    /**
     * 外部キーの列が、どれかの索引の先頭の列(順は問わない)になっているか。
     *
     * @param  list<int>  $foreignKey
     * @param  array<int, list<int>>  $indexes
     */
    private function isCovered(array $foreignKey, array $indexes): bool
    {
        foreach ($indexes as $index) {
            $leading = array_slice($index, 0, count($foreignKey));
            sort($leading);
            $sorted = $foreignKey;
            sort($sorted);

            if ($leading === $sorted) {
                return true;
            }
        }

        return false;
    }
}
