<?php

namespace Tests\Feature\Actions\Upload;

use App\Actions\Upload\Api\StoreUploads;
use App\Actions\Upload\PruneUploadFiles;
use App\Console\Commands\Upload\PruneUploadFiles as PruneUploadFilesCommand;
use App\Models\Upload\Upload;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 台帳が持たない実体の掃除(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 6 節の 5、検査 = 8 節の「掃除」)。
 * 境界は書いてから 1 日(86,400 秒)の前後 1 秒とちょうどで確かめる。偽のディスクで流す(開発用の置き場に触れない)。
 */
final class PruneUploadFilesTest extends TransactionalTestCase
{
    use BuildsUploads;

    private const DAY = 86_400;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-21 03:00:00 UTC');
        $this->fakeUploadDisk();
    }

    public function test_it_deletes_the_old_files_that_have_no_row_or_a_purged_row_and_leaves_the_rows_unchanged(): void
    {
        $orphan = $this->file('orphan.pdf', self::DAY + 1);
        $purged = $this->file('purged.pdf', self::DAY + 1);
        $row = Upload::factory()->purged()->create(['path' => $purged]);
        $before = Upload::query()->findOrFail($row->id)->only(['path', 'purged_at', 'purge_due_on']);

        $this->assertSame(['deleted' => 2, 'failed' => 0], app(PruneUploadFiles::class)->execute());

        Storage::disk('local')->assertMissing([$orphan, $purged]);
        $after = Upload::query()->findOrFail($row->id)->only(['path', 'purged_at', 'purge_due_on']);
        $this->assertEquals($before, $after);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function ages(): array
    {
        return [
            '1 日と 1 秒' => [self::DAY + 1, true],
            '1 日ちょうど' => [self::DAY, false],
            '1 日に 1 秒足りない' => [self::DAY - 1, false],
        ];
    }

    #[DataProvider('ages')]
    public function test_only_a_file_written_more_than_a_day_ago_is_deleted(int $age, bool $deleted): void
    {
        $path = $this->file('a.pdf', $age);

        $this->assertSame(['deleted' => $deleted ? 1 : 0, 'failed' => 0], app(PruneUploadFiles::class)->execute());

        $this->assertSame(! $deleted, Storage::disk('local')->exists($path));
    }

    public function test_a_file_of_a_live_row_stays_however_old_it_is(): void
    {
        $path = $this->file('live.pdf', self::DAY + 1);
        Upload::factory()->create(['path' => $path]);

        $this->assertSame(['deleted' => 0, 'failed' => 0], app(PruneUploadFiles::class)->execute());

        Storage::disk('local')->assertExists($path);
    }

    public function test_a_stuck_file_is_reported_and_counted_and_the_others_are_deleted(): void
    {
        Exceptions::fake();
        $stuck = $this->file('stuck.pdf', self::DAY + 1);
        $other = $this->file('other.pdf', self::DAY + 1);
        $this->makeUploadsUndeletable(fn (string $path): bool => $path === $stuck);

        $this->assertSame(['deleted' => 1, 'failed' => 1], app(PruneUploadFiles::class)->execute());

        Exceptions::assertReported(UnableToDeleteFile::class);
        Storage::disk('local')->assertExists($stuck);
        Storage::disk('local')->assertMissing($other);
    }

    public function test_it_handles_more_files_than_one_chunk_and_keeps_the_file_of_a_live_row(): void
    {
        $count = PruneUploadFiles::CHUNK_SIZE + 1;

        for ($index = 0; $index < $count; $index++) {
            $this->file("orphan-{$index}.pdf", self::DAY + 1);
        }

        $live = $this->file('live.pdf', self::DAY + 1);
        Upload::factory()->create(['path' => $live]);

        $this->assertSame(['deleted' => $count, 'failed' => 0], $this->prune());

        Storage::disk('local')->assertExists($live);
        $this->assertSame(1, $this->storedFileCount());
    }

    public function test_it_ends_with_nothing_when_the_directory_is_missing(): void
    {
        $this->assertSame(['deleted' => 0, 'failed' => 0], $this->prune());
    }

    public function test_it_ends_with_nothing_when_the_directory_is_empty(): void
    {
        Storage::disk('local')->makeDirectory(StoreUploads::DIRECTORY);

        $this->assertSame(['deleted' => 0, 'failed' => 0], $this->prune());
    }

    public function test_the_command_ends_with_success_when_nothing_is_stuck(): void
    {
        $path = $this->file('a.pdf', self::DAY + 1);

        $this->assertSame(0, Artisan::call(PruneUploadFilesCommand::class));

        $this->assertStringContainsString('1 件消した', Artisan::output());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_command_fails_when_a_file_cannot_be_deleted(): void
    {
        Exceptions::fake();
        $stuck = $this->file('stuck.pdf', self::DAY + 1);
        $other = $this->file('other.pdf', self::DAY + 1);
        $this->makeUploadsUndeletable(fn (string $path): bool => $path === $stuck);

        $this->assertSame(1, Artisan::call(PruneUploadFilesCommand::class));

        $this->assertStringContainsString('消せなかった添付の実体が 1 件ある', Artisan::output());
        Exceptions::assertReported(UnableToDeleteFile::class);
        Storage::disk('local')->assertMissing($other);
    }

    public function test_the_scheduler_runs_it_every_day_just_after_midnight_in_tokyo(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'upload:prune-files'),
        ));

        $this->assertSame([['0 0 * * *', 'Asia/Tokyo']], array_map(
            fn (ScheduledEvent $event): array => [
                $event->expression,
                $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : $event->timezone,
            ],
            $events,
        ));
    }

    /**
     * @return array{deleted: int, failed: int}
     */
    private function prune(): array
    {
        return app(PruneUploadFiles::class)->execute();
    }

    /**
     * 書いてから秒数が経った実体を uploads/ の直下に置く。
     *
     * @return string 台帳の path と同じ形のパス
     */
    private function file(string $name, int $ageSeconds): string
    {
        $path = StoreUploads::DIRECTORY.'/'.$name;
        Storage::disk('local')->put($path, 'x');
        touch(Storage::disk('local')->path($path), CarbonImmutable::now()->getTimestamp() - $ageSeconds);

        return $path;
    }
}
