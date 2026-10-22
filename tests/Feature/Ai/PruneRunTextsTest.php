<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PruneRunTexts;
use App\Console\Commands\Ai\PruneRunTexts as PruneRunTextsCommand;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 原文・例外の文の保存期間(対象と期限の正本 =
 * docs の 設計/AI/データと失敗と検査.md 6 節)。境界は 90 日の前後 1 秒で確かめる。
 */
final class PruneRunTextsTest extends TransactionalTestCase
{
    public function test_deletes_only_the_texts_older_than_the_retention_and_keeps_the_runs(): void
    {
        $this->freezeTime();
        $expired = $this->runWithText(now()->subDays(90)->subSecond());
        $kept = $this->runWithText(now()->subDays(90)->addSecond());

        $this->assertSame(['texts' => 1, 'errorMessages' => 0], app(PruneRunTexts::class)->execute());

        $this->assertSame([$kept], AiRunText::query()->whereIn('ai_run_id', [$expired, $kept])->pluck('ai_run_id')->all());
        $this->assertSame(2, AiRun::query()->whereKey([$expired, $kept])->count());
    }

    public function test_blanks_only_the_exception_messages_older_than_the_retention_and_keeps_the_failed_runs(): void
    {
        $this->freezeTime();
        $expired = AiRun::factory()->failed()->create(['created_at' => now()->subDays(90)->subSecond()]);
        $kept = AiRun::factory()->failed()->create(['created_at' => now()->subDays(90)->addSecond()]);

        $this->assertSame(['texts' => 0, 'errorMessages' => 1], app(PruneRunTexts::class)->execute());

        $this->assertSame(['', AiRunStatus::Failed, AiRunErrorCode::Provider], [$expired->refresh()->error_message, $expired->status, $expired->error_code]);
        $this->assertSame('接続先の誤り', $kept->refresh()->error_message);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidRetentions(): array
    {
        return ['0 日' => [0], '負' => [-1], '文字列' => ['90'], '無い' => [null]];
    }

    #[DataProvider('invalidRetentions')]
    public function test_a_retention_that_is_not_a_positive_integer_stops_instead_of_deleting_everything(mixed $days): void
    {
        config(['ai_runs.retention_days' => $days]);

        $this->expectException(LogicException::class);

        PruneRunTextsCommand::failedJobRetentionHours();
    }

    public function test_the_failed_jobs_are_kept_for_the_retention(): void
    {
        $this->assertSame(2160, PruneRunTextsCommand::failedJobRetentionHours());
    }

    public function test_the_command_prunes_and_the_scheduler_runs_it_daily(): void
    {
        $this->freezeTime();
        $expired = $this->runWithText(now()->subDays(91));
        $emptyDirectory = sys_get_temp_dir().'/prune-run-texts-test-'.bin2hex(random_bytes(6));
        config(['ai_runs.transcription.temp_directory' => $emptyDirectory]);

        $this->assertSame(0, Artisan::call('ai:prune-run-texts'));
        $this->assertSame(
            "AI の原文を 1 件消し、例外の文を 0 件空にした\n残った文字起こしの一時ファイルのディレクトリを 0 件消した\n",
            Artisan::output(),
        );
        $this->assertSame(0, AiRunText::query()->whereKey($expired)->count());

        $this->assertSame(['0 0 * * *'], $this->scheduled('ai:prune-run-texts'));
        $this->assertSame(['0 0 * * *'], $this->scheduled('queue:prune-failed --hours=2160'));
    }

    /**
     * コマンドの行を含む予定の時刻の式。
     *
     * @return list<string>
     */
    private function scheduled(string $command): array
    {
        $events = array_filter(app(Schedule::class)->events(), fn ($event): bool => str_contains((string) $event->command, $command));

        return array_values(array_map(fn ($event): string => $event->expression, $events));
    }

    private function runWithText(CarbonImmutable $createdAt): int
    {
        return AiRunText::factory()->create(['created_at' => $createdAt])->ai_run_id;
    }
}
