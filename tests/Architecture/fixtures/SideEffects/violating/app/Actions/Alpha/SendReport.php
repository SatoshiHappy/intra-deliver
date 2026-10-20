<?php

namespace App\Actions\Alpha;

use Illuminate\Foundation\Bus\Dispatchable;

/**
 * キューに投入するジョブ(Dispatchable を使う)
 */
final class SendReport
{
    use Dispatchable;

    public function handle(): void {}
}
