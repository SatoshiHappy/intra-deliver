<?php

namespace Tests\Feature\Reset;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 既定の seeder の代わり(初期化のテストを速くする)。失敗したジョブの表に 1 行入れ(連番が初めの値から振り直されることを見る)、呼ばれた順を残す。
 */
class StubInitialSeeder extends Seeder
{
    /** @var list<string> 呼ばれた seeder の順 */
    public static array $calls = [];

    public function run(): void
    {
        self::$calls[] = static::class;
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
            'exception' => '初期データ', 'failed_at' => now(),
        ]);
    }
}
