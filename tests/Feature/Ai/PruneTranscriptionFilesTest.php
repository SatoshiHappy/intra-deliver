<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PruneTranscriptionFiles;
use App\Models\Ai\AiRun;
use Illuminate\Support\Facades\File;
use Tests\Support\TransactionalTestCase;

/**
 * worker が落ちて残った区切りの一時ファイルの削除(docs の 設計/AI/データと失敗と検査.md 6 節): 置き場の下の実行の id のディレクトリのうち、
 * 処理中の実行のものは残り、終わった実行・行の無い実行のものは消える。実行の id でない名前のディレクトリには触れない。
 */
final class PruneTranscriptionFilesTest extends TransactionalTestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/prune-transcription-files-test-'.bin2hex(random_bytes(6));
        config(['ai_runs.transcription.temp_directory' => $this->base]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_keeps_the_directories_of_running_runs_and_deletes_the_others(): void
    {
        $running = AiRun::factory()->running()->create()->id;
        $succeeded = AiRun::factory()->succeeded()->create()->id;
        $queued = AiRun::factory()->create()->id;
        $missing = $queued + 1_000_000;

        foreach ([$running, $succeeded, $queued, $missing, 'other'] as $name) {
            File::ensureDirectoryExists("{$this->base}/{$name}");
            File::put("{$this->base}/{$name}/chunk-001.mp3", 'x');
        }

        $this->assertSame(3, app(PruneTranscriptionFiles::class)->execute());

        $left = array_map('basename', File::directories($this->base));
        sort($left);
        $this->assertSame([(string) $running, 'other'], $left);
    }

    public function test_a_missing_base_directory_deletes_nothing(): void
    {
        $this->assertSame(0, app(PruneTranscriptionFiles::class)->execute());
    }
}
