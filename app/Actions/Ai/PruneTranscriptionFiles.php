<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Queries\Ai\TranscriptionSettings;
use Illuminate\Support\Facades\File;

/**
 * worker が落ちて残った区切りの一時ファイルを消す(docs の 設計/AI/データと失敗と検査.md 6 節)。置き場の下の実行の id のディレクトリのうち、
 * 処理中でない実行(か行の無い実行)のものを消す。処理中の実行のディレクトリは、その実行が終わりに自分で消す。
 */
class PruneTranscriptionFiles
{
    public function __construct(private TranscriptionSettings $settings) {}

    /**
     * @return int 消したディレクトリの数
     */
    public function execute(): int
    {
        $base = $this->settings->tempDirectory();
        $ids = [];

        foreach (File::isDirectory($base) ? File::directories($base) : [] as $directory) {
            $name = basename($directory);

            if (ctype_digit($name)) {
                $ids[(int) $name] = $directory;
            }
        }

        $running = AiRun::query()->whereKey(array_keys($ids))->where('status', AiRunStatus::Running)
            ->pluck('id')->all();
        $stale = array_diff_key($ids, array_flip($running));

        foreach ($stale as $directory) {
            File::deleteDirectory($directory);
        }

        return count($stale);
    }
}
