<?php

namespace Tests\Support;

use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;

/**
 * テストの中だけで使うホームの節(docs の 設計/メニュー/ホーム.md 6.2 の口)。決めた節(null = 出さない)を返す。
 *
 * @phpstan-import-type HomeLeadSection from HomeSections
 * @phpstan-import-type HomeTilesSection from HomeSections
 */
final readonly class FixedHomeSection implements HomeSections
{
    /**
     * @param  HomeLeadSection|HomeTilesSection|null  $section
     */
    public function __construct(private ?array $section) {}

    /**
     * @return HomeLeadSection|HomeTilesSection|null
     */
    public function section(AccessContext $context): ?array
    {
        return $this->section;
    }
}
