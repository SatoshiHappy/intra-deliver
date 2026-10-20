<?php

namespace Database\Seeders\SeederFixture;

use App\Actions\Master\CreateLocale;
use App\Models\Master\Locale;
use Illuminate\Database\Seeder;

/**
 * seeder は業務処理を呼び、モデルを読む(factory と Faker を使わない)
 */
final class CallsActions extends Seeder
{
    public function run(CreateLocale $createLocale): void
    {
        $createLocale->execute('ja');
        Locale::query()->where('code', 'ja')->sole();
    }
}
