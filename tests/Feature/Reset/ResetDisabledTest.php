<?php

namespace Tests\Feature\Reset;

use App\Models\Reset\ResetRun;
use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 設定がオフの配備(既定。docs の 設計/公開の配備/毎日の初期化.md 5 節と 2 節の「始めるか」の表のオフの 2 行)。
 * 「始めるか」の表の「起きない」行(予定 × オフ)は、予定に出ない(schedule:list)ことで確かめる。
 */
final class ResetDisabledTest extends TransactionalTestCase
{
    use RunsCommands;

    public function test_the_schedule_has_neither_the_reset_nor_the_watch(): void
    {
        $this->assertFalse(config('reset.enabled'));

        $this->assertStringNotContainsString('reset:', $this->assertCommand('schedule:list', 0));
    }

    #[TestWith(['reset:data'])]
    #[TestWith(['reset:daily'])]
    #[TestWith(['reset:watch'])]
    public function test_a_command_does_nothing_and_fails(string $command): void
    {
        $this->assertCommand($command, 1, '設定がオフ');

        $this->assertSame(0, ResetRun::query()->count());
    }

    /** 5 節の「置き場の確かめ」がオフでは働かない行(置き場がファイルのまま = テストの既定) */
    public function test_the_stores_are_not_checked(): void
    {
        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('file', config('app.maintenance.driver'));
    }
}
