<?php

namespace Tests\Feature\Migrations;

use App\Models\Master\User;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * 実行に訪問者の ID を足すマイグレーション(docs の 設計/AI/データと失敗と検査.md 6 節): 列は UUID で NULL 可、前からある行は NULL。
 * down は列を消す。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AddVisitorIdToAiRunsMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_23_100001_add_visitor_id_to_ai_runs_table.php';

    public function test_the_column_is_a_nullable_uuid(): void
    {
        $column = collect(Schema::getColumns('ai_runs'))->firstWhere('name', 'visitor_id');

        $this->assertSame(['uuid', true], [$column['type'] ?? null, $column['nullable'] ?? null]);
    }

    public function test_the_runs_from_before_have_no_visitor_id(): void
    {
        $this->migrator()->reset([$this->path()]);
        $userId = User::factory()->create()->id;
        $this->insertRun($userId);

        $this->migrator()->run([$this->path()]);

        $this->assertNull(DB::table('ai_runs')->where('requester_user_id', $userId)->value('visitor_id'));
    }

    public function test_down_drops_the_column(): void
    {
        $this->migrator()->reset([$this->path()]);

        $this->assertFalse(Schema::hasColumn('ai_runs', 'visitor_id'));
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function path(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }

    /**
     * 文章の実行の行を表に直に足す(列の足し引きの前後で書けるように、必要な列だけ)。
     */
    private function insertRun(int $userId): void
    {
        DB::table('ai_runs')->insert([
            'kind' => 'meeting.minutes',
            'status' => 'queued',
            'requester_user_id' => $userId,
            'source_record_ids' => '[]',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'max_output_tokens' => 100,
            'reserved_cost_usd' => '0.010000',
            'created_at' => now(),
        ]);
    }
}
