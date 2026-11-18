<?php

namespace App\Actions\Ai\Runs;

use App\Actions\Ai\Core\Ported\PortedHelper;

/**
 * 繋ぎが版を選ぶ 1 か所の外で具体的な版を参照するのは禁止
 */
final class RunUsesVersion
{
    public function __construct(public PortedHelper $version) {}
}
