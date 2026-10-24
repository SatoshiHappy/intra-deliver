<?php

namespace Tests\Feature\Reset;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 入れている間にファイルを書く seeder(手順 6 で消えないことを見る)。呼ばれた順も残す。
 * ファイルの名前は毎回変わる(docs の 設計/公開の配備/毎日の初期化.md 1 節の約束の 4 項め)。
 */
class WritesFileSeeder extends Seeder
{
    /** @var list<string> 書いたファイルの名前(古い順) */
    public static array $files = [];

    public function run(): void
    {
        StubInitialSeeder::$calls[] = static::class;
        $file = 'private/uploads/written-while-seeding-'.Str::uuid().'.txt';
        Storage::disk((string) config('reset.disk'))->put($file, '入れている間');
        self::$files[] = $file;
    }
}
