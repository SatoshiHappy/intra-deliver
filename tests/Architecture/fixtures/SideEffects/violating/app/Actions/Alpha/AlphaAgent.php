<?php

namespace App\Actions\Alpha;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * laravel/ai のエージェント
 */
final class AlphaAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'instructions';
    }
}
