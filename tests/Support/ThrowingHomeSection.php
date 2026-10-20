<?php

namespace Tests\Support;

use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;
use RuntimeException;

/**
 * テストの中だけで使う、契約に反して例外を投げるホームの節(実装は投げない契約 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1。
 * 投げればホームが 500 になることを確かめる)。
 */
final readonly class ThrowingHomeSection implements HomeSections
{
    public function section(AccessContext $context): ?array
    {
        throw new RuntimeException('ホームの節を作れなかった');
    }
}
