<?php

namespace App\Actions\Reset;

use App\Actions\Reset\Api\ResetParticipant;
use App\Enums\Reset\ResetOutcome;
use App\Enums\Reset\ResetResult;
use App\Enums\Reset\ResetTrigger;
use App\Models\Reset\ResetRun;
use App\Queries\Reset\ResetSettings;
use App\Queries\Reset\ResetTables;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * 公開デモの毎日の初期化の 1 回(手順 0〜7 = docs の 設計/公開の配備/毎日の初期化.md 2 節、失敗と落ちたとき = 4 節、方式 = ADR 0024)。
 * 全表を空にすることと app-storage の全ファイルを消すことに限って、各モジュールの口を通さない(ADR 0024 の決定 7)。
 */
class ResetToInitialData
{
    /**
     * @param  iterable<ResetParticipant>  $participants
     */
    public function __construct(
        private ResetSettings $settings,
        private ResetTables $tables,
        private ResetLock $lock,
        private ResetStops $stops,
        private LiftStaleReset $lift,
        private SeedInitialData $seed,
        #[Tag(ResetParticipant::TAG)] private iterable $participants,
    ) {}

    /**
     * 手順 0: 始めるかを決め、始めるなら 1 回を流す
     *
     * @throws ResetFailed 記録の行を書いた後の手順 1〜7 が失敗したとき
     * @throws Throwable 失敗の片付けが投げたとき(その例外の連鎖の中に ResetFailed が入る)
     */
    public function execute(ResetTrigger $trigger): ResetReport
    {
        if (! $this->settings->enabled()) {
            return new ResetReport(ResetOutcome::Disabled);
        }

        if (! $this->lock->acquire()) {
            return new ResetReport(ResetOutcome::Locked);
        }

        try {
            $this->lift->whileLocked();
            $notStarted = $this->notStartedOutcome($trigger);

            return $notStarted !== null ? new ResetReport($notStarted) : $this->run($trigger);
        } finally {
            $this->lock->release();
        }
    }

    /** 始めなかった回の結果(始めるなら null。「始めるか」の表 = 毎日の初期化.md 2 節) */
    private function notStartedOutcome(ResetTrigger $trigger): ?ResetOutcome
    {
        if ($this->stops->unmarkedMaintenance()) {
            return ResetOutcome::Maintenance;
        }

        $tried = ResetRun::query()->where('reset_on', $this->settings->dayOf(CarbonImmutable::now()))->exists();

        return $trigger === ResetTrigger::Schedule && $tried ? ResetOutcome::AlreadyTried : null;
    }

    /**
     * 記録の行を書いて手順 1〜7 を流す。
     *
     * @throws ResetFailed 手順 1〜7 の例外(鎖錠を持っていれば片付けた後に、この回の結果を付けて投げる)
     * @throws Throwable 停止を解く・行を閉じる片付けが投げたとき(その例外の連鎖の中に ResetFailed が入る)
     */
    private function run(ResetTrigger $trigger): ResetReport
    {
        $now = CarbonImmutable::now();
        $run = ResetRun::query()->create([
            'reset_on' => $this->settings->dayOf($now),
            'trigger' => $trigger,
            'started_at' => $now,
        ]);
        [$jobs, $waited, $reload] = [0, 0, null];
        $closed = false;

        try {
            [$jobs, $waited] = $this->stopNewWork($run->id);
            $files = Storage::disk($this->settings->disk())->allFiles();
            $reload = $this->reload($run);
            $this->deleteFiles($files);
            $this->close($run, ResetResult::Succeeded);
            $closed = true;

            $peak = memory_get_peak_usage(true);

            return new ResetReport(ResetOutcome::Succeeded, $run->id, $jobs, $waited, $reload, $peak);
        } catch (Throwable $error) {
            $peak = memory_get_peak_usage(true);
            $report = new ResetReport(ResetOutcome::Failed, $run->id, $jobs, $waited, $reload, $peak);

            throw new ResetFailed($report, $error);
        } finally {
            // finally の中で投げた例外には飛んでいる ResetFailed が繋がるので、片付けを catch でなくここに置く
            if (! $closed && $this->lock->held()) {
                $this->stops->leaveMaintenance();
                $this->stops->resumeQueue();
                $this->close($run, ResetResult::Failed);
            }
        }
    }

    /**
     * 手順 1〜4 = 毎日の初期化.md 2 節。
     *
     * @return array{int, int} 手順 2 で待ったジョブの数と秒
     *
     * @throws RuntimeException 手順 4 で処理中のジョブがあるとき
     */
    private function stopNewWork(int $runId): array
    {
        $retryAfter = $this->settings->retryAfter();
        $notice = $this->settings->seconds('worker_notice_seconds');
        $this->stops->pauseQueue($retryAfter + $notice + $this->settings->seconds('pause_margin_seconds'));
        Sleep::for($notice)->seconds();

        $start = CarbonImmutable::now();
        $jobs = $this->tables->processingJobs();

        while ($this->tables->processingJobs() > 0 && $start->diffInSeconds(CarbonImmutable::now()) < $retryAfter) {
            Sleep::for($this->settings->seconds('job_poll_seconds'))->seconds();
        }

        $waited = (int) $start->diffInSeconds(CarbonImmutable::now());
        $this->stops->enterMaintenance($runId);
        Sleep::for($this->settings->seconds('request_wait_seconds'))->seconds();

        if (($left = $this->tables->processingJobs()) > 0) {
            throw new RuntimeException("メンテナンスの状態にした後も処理中のジョブが {$left} 件ある");
        }

        return [$jobs, $waited];
    }

    /**
     * 手順 5 = 毎日の初期化.md 2 節。
     *
     * @return float かかった秒
     */
    private function reload(ResetRun $run): float
    {
        $start = microtime(true);

        DB::transaction(function () use ($run): void {
            if (! $this->lock->held()) {
                throw new ResetLockLost('入れ直しの前に初期化の鎖錠を失っていた');
            }

            $kept = [$this->settings->migrationsTable(), $run->getTable()];

            foreach ($this->participants as $participant) {
                $participant->beforeTruncate();
                $kept = [...$kept, ...$participant->keptTables()];
            }

            $cache = $this->settings->cacheTables();
            $this->truncate(array_values(array_diff($this->tables->all(), $kept, $cache)));
            $this->seed->execute();
            $this->truncate($cache);
            $run->update(['reloaded_at' => CarbonImmutable::now()]);
        });

        return round(microtime(true) - $start, 1);
    }

    /**
     * @param  list<string>  $tables
     */
    private function truncate(array $tables): void
    {
        $names = array_map(fn (string $table): string => '"'.str_replace('"', '""', $table).'"', $tables);

        DB::statement('TRUNCATE TABLE '.implode(', ', $names).' RESTART IDENTITY');
    }

    /**
     * 手順 6 = 毎日の初期化.md 2 節。
     *
     * @param  array<string>  $files
     *
     * @throws RuntimeException 消せなかったとき(4 節)
     */
    private function deleteFiles(array $files): void
    {
        if ($files !== [] && ! Storage::disk($this->settings->disk())->delete($files)) {
            throw new RuntimeException('控えたファイルの一部を消せなかった('.count($files).' 件のうち)');
        }
    }

    /**
     * 手順 7 = 毎日の初期化.md 2 節。
     *
     * @throws ResetLockLost 鎖錠を失っていたとき
     */
    private function close(ResetRun $run, ResetResult $result): void
    {
        if (! $this->lock->held()) {
            throw new ResetLockLost('記録を閉じる前に初期化の鎖錠を失っていた');
        }

        $run->update(['result' => $result, 'finished_at' => CarbonImmutable::now()]);
    }
}
