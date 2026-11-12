<?php

namespace Tests\Feature\Ai;

use App\Queries\Records\Api\RecordModuleDirectory;

/**
 * 一覧で見た後に業務モジュールが消えた競合を作る口(テストの中だけ。一覧に、もう無い業務モジュールを足して返す)。
 */
final class RacingModuleDirectory extends RecordModuleDirectory
{
    public function topLevelModules(): array
    {
        return [...parent::topLevelModules(), ['code' => 'm_vanished', 'name' => 'm_vanished']];
    }
}
