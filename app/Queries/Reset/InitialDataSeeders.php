<?php

namespace App\Queries\Reset;

use App\Queries\Reset\Api\ResetSeeder;
use Illuminate\Container\Attributes\Tag;

/**
 * 初期データの並び(docs の 設計/公開の配備/毎日の初期化.md 7 節)。初期化と、初期データだけを入れるコマンドが同じ並びを使う 1 か所。
 */
class InitialDataSeeders
{
    /**
     * @param  iterable<ResetSeeder>  $seeders
     */
    public function __construct(#[Tag(ResetSeeder::TAG)] private iterable $seeders) {}

    /**
     * db:seed に渡す引数の並び。
     *
     * @return list<array<string, string|bool>>
     */
    public function seedArguments(): array
    {
        $base = ['--force' => true, '--no-interaction' => true];
        $arguments = [$base];

        foreach ($this->seeders as $seeder) {
            $arguments[] = [...$base, '--class' => $seeder->seederName()];
        }

        return $arguments;
    }
}
