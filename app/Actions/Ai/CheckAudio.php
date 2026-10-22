<?php

namespace App\Actions\Ai;

use Illuminate\Support\Facades\File;

/**
 * 音声のファイル 1 つに、文字起こしの実物の呼び方(長さの測り → 区切りの変換 → 区切りの測り = docs の 設計/AI/実行と費用.md の 4.2 節)を
 * 流す(`ai:check-audio` が使う。イメージの ffmpeg・ffprobe がアプリの呼び方と受ける形式に足りるかの確かめ)。DB に触れない。
 * 区切りは使い捨てのディレクトリに書き、終わりに(失敗でも)消す。
 */
class CheckAudio
{
    public function __construct(private AudioConverter $converter) {}

    /**
     * @return array{seconds: float, chunks: int} 音声の長さ(秒)と区切りの数
     *
     * @throws AudioUnreadable 測れない・復号できない音声のとき
     * @throws AudioConversionFailed 変換できない・時間の上限を超えたとき
     */
    public function execute(string $file, int $chunkSeconds): array
    {
        $directory = sys_get_temp_dir().'/ai-check-audio-'.bin2hex(random_bytes(6));

        try {
            $seconds = $this->converter->measure($file);
            $chunks = $this->converter->split($file, $directory, $chunkSeconds);

            foreach ($chunks as $chunk) {
                $this->converter->measure($chunk->path);
            }

            return ['seconds' => $seconds, 'chunks' => count($chunks)];
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
