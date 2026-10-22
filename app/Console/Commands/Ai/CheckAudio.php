<?php

namespace App\Console\Commands\Ai;

use App\Actions\Ai\AudioConversionFailed;
use App\Actions\Ai\AudioUnreadable;
use App\Actions\Ai\CheckAudio as CheckAudioAction;
use App\Queries\Ai\KindSettings;
use Illuminate\Console\Command;

/**
 * 音声のファイルに文字起こしの実物の呼び方を流して、イメージの ffmpeg・ffprobe がアプリの呼び方と受ける形式に足りるかを確かめるコマンド
 * (docs の 設計/公開の配備/構成.md の 3.3.1 節。公開用のイメージの build と実機の検証が使う)。呼び方を写さず業務処理を通すので、
 * アプリの引数を変えても確かめが古くならない。区切りの長さは設定にある文字起こしの種類ごとの値。DB に触れない。
 */
final class CheckAudio extends Command
{
    protected $signature = 'ai:check-audio {files* : 確かめる音声のファイル}';

    protected $description = '音声のファイルに文字起こしの長さの測りと区切りの変換を流して確かめる(失敗があれば終了コード 1)';

    public function handle(CheckAudioAction $checkAudio, KindSettings $kinds): int
    {
        $codes = $kinds->codes(KindSettings::TRANSCRIPTION);

        if ($codes === []) {
            $this->error('文字起こしの種類が設定(config/ai_runs.php の kinds)に無い');

            return self::FAILURE;
        }

        $failed = false;

        foreach ((array) $this->argument('files') as $file) {
            foreach ($codes as $code) {
                $chunkSeconds = $kinds->transcription($code)['chunk_seconds'] ?? 0;
                $failed = ! $this->check($checkAudio, (string) $file, $chunkSeconds) || $failed;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** 1 つの音声を 1 つの区切りの長さで確かめて 1 行を出す。通れば true */
    private function check(CheckAudioAction $checkAudio, string $file, int $chunkSeconds): bool
    {
        try {
            $checked = $checkAudio->execute($file, $chunkSeconds);
        } catch (AudioUnreadable|AudioConversionFailed $exception) {
            $this->error("{$file}: {$exception->getMessage()}");

            return false;
        }

        $this->info(sprintf(
            '%s: 長さ %.3f 秒・区切り %d 個(区切りの長さ %d 秒)',
            $file,
            $checked['seconds'],
            $checked['chunks'],
            $chunkSeconds,
        ));

        return $checked['chunks'] > 0;
    }
}
