<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\InstructionBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * 手元だけのリポジトリのプロバイダに見立てる(register で口を移した版に結ぶだけ)。一時の根の一覧から登録する。
 */
final class PrivateInstructionProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InstructionBuilder::class, ProblemInstructionBuilder::class);
    }
}
