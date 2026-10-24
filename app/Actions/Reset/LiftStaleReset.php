<?php

namespace App\Actions\Reset;

use App\Enums\Reset\ResetResult;
use App\Models\Reset\ResetRun;

/**
 * 見張り(docs の 設計/公開の配備/毎日の初期化.md 4 節の見張りの表)。初期化の手順 0 も同じ片付けをするので、{@see self::whileLocked()} を分けた。
 */
class LiftStaleReset
{
    public function __construct(private ResetLock $lock, private ResetStops $stops) {}

    /**
     * @return array{maintenance: bool, runs: list<int>}|null 解いたか・閉じた行の番号。鎖錠を取れなければ null
     */
    public function execute(): ?array
    {
        if (! $this->lock->acquire()) {
            return null;
        }

        try {
            return $this->whileLocked();
        } finally {
            $this->lock->release();
        }
    }

    /**
     * 鎖錠を持っている呼び手が片付ける(することは 4 節の見張りの表)。
     *
     * @return array{maintenance: bool, runs: list<int>}
     */
    public function whileLocked(): array
    {
        $maintenance = $this->stops->markedMaintenance();

        if ($maintenance) {
            $this->stops->leaveMaintenance();
        }

        $this->stops->resumeQueue();
        $runs = ResetRun::query()->whereNull('result')->orderBy('id')->get();

        foreach ($runs as $run) {
            $run->update(['result' => ResetResult::Failed, 'finished_at' => now()]);
        }

        $ids = array_values($runs->map(fn (ResetRun $run): int => $run->id)->all());

        return ['maintenance' => $maintenance, 'runs' => $ids];
    }
}
