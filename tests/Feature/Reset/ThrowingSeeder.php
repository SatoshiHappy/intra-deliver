<?php

namespace Tests\Feature\Reset;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 途中で失敗する seeder(書いた後に投げる。書いた行も巻き戻ることを見る) */
class ThrowingSeeder extends Seeder
{
    public function run(): void
    {
        StubInitialSeeder::$calls[] = static::class;
        DB::table('failed_jobs')->insert([
            'uuid' => 'written-before-failing', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
            'exception' => '失敗の前に書いた行', 'failed_at' => now(),
        ]);

        throw new RuntimeException('seeder の失敗');
    }
}
