<?php

namespace Tests\Feature\Models\Workflow;

use Illuminate\Support\Facades\DB;
use Tests\Support\TransactionalTestCase;
use Tests\Support\WorkflowAttachmentSchema;
use Tests\Support\WorkflowCaseHandlingSchema;
use Tests\Support\WorkflowConstraintNames;
use Tests\Support\WorkflowSchema;

/**
 * ワークフローの 18 表の制約と索引の名前(docs の 設計/ワークフロー/データモデル/直列化と検査.md 4 節・6 節と、段 3a の ルートの深さ/データモデル/直列化と検査.md
 * 5 節・7 節: 名前は 設計/共通マスタ/共通の規則.md 9 節の形で、63 バイトを超える名前を PostgreSQL が切り詰めても重ならない)。設計どおりの名前(主キーを除く 255 個)を切り詰めた一覧と、マイグレーションの後の
 * DB の名前(主キーを含む)を照合する。ワークフローの表だけを見る。
 */
final class ConstraintNamesTest extends TransactionalTestCase
{
    private const IDENTIFIER_MAX_BYTES = 63;

    public function test_the_truncated_designed_names_do_not_collide(): void
    {
        $truncated = $this->truncatedNames();

        $this->assertCount(255, WorkflowConstraintNames::NAMES);
        $this->assertSame(count(WorkflowConstraintNames::NAMES), count(array_unique($truncated)));
    }

    public function test_the_database_has_exactly_the_designed_names(): void
    {
        $tables = '{'.implode(',', $this->tables()).'}';
        $constraints = DB::table('pg_constraint')->whereRaw('conrelid::regclass::text = any(?)', [$tables])->where('contype', '<>', 'n')->pluck('conname')->all();
        $indexes = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))->whereRaw('tablename = any(?)', [$tables])->pluck('indexname')->all();
        $primaryKeys = array_map(fn (string $table): string => "{$table}_pkey", $this->tables());

        $actual = array_values(array_unique([...$constraints, ...$indexes]));
        $expected = array_values(array_unique([...$primaryKeys, ...$this->truncatedNames()]));
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    /**
     * ワークフローの表(案件の運用で足した表と、一時保存の添付の表を含む)。
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return array_keys(
            WorkflowSchema::COLUMNS + WorkflowCaseHandlingSchema::COLUMNS + WorkflowAttachmentSchema::COLUMNS,
        );
    }

    /**
     * 設計どおりの名前を、PostgreSQL の識別子の上限で切り詰めたもの。
     *
     * @return list<string>
     */
    private function truncatedNames(): array
    {
        return array_map(fn (string $name): string => substr($name, 0, self::IDENTIFIER_MAX_BYTES), WorkflowConstraintNames::NAMES);
    }
}
