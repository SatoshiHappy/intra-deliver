<?php

namespace Tests\Feature\Reset;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/** 入れている間にファイルを書く seeder(手順 6 で消えないことを見る)。呼ばれた順も残す */
class WritesFileSeeder extends Seeder
{
    public const FILE = 'private/uploads/written-while-seeding.txt';

    public function run(): void
    {
        StubInitialSeeder::$calls[] = static::class;
        Storage::disk((string) config('reset.disk'))->put(self::FILE, '入れている間');
    }
}
