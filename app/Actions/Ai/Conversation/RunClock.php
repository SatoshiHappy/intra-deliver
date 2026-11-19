<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\CoreClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Sleep;

/**
 * 実行の時計と待ち(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「時計と待ちの口」)。実行の時間は処理中にした時からの壁時計で
 * (設計/AI/エージェントの実行/途中の表示と上限.md 9.7)、送り直しの待ちも数える(待ちを残りの時間で頭打ちにして、ジョブの時間の上限を守るため)。
 */
final readonly class RunClock implements CoreClock
{
    /**
     * @param  int  $timeoutSeconds  会話の種類の実行の時間の上限
     */
    public function __construct(private DateTimeImmutable $startedAt, private int $timeoutSeconds) {}

    public function now(): DateTimeImmutable
    {
        return CarbonImmutable::now();
    }

    public function wait(int $milliseconds): void
    {
        Sleep::for($milliseconds)->milliseconds();
    }

    /** 実行の時間の残り(ミリ秒。上限ちょうどは 0、超えたら負) */
    public function remainingMilliseconds(): int
    {
        $elapsed = (int) round(((float) $this->now()->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000);

        return $this->timeoutSeconds * 1000 - $elapsed;
    }

    /** 実行の時間の上限を超えたか */
    public function exceeded(): bool
    {
        return $this->remainingMilliseconds() < 0;
    }
}
