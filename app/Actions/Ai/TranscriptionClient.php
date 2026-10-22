<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\TranscribedSegment;
use App\Queries\Ai\KindSettings;
use Laravel\Ai\Transcription;
use Throwable;

/**
 * 文字起こしの接続先を呼ぶ唯一のクラス(docs の 設計/AI/実行と費用.md 4 節。副作用 ai は台帳②で App\Actions\Ai だけ)。区切りの 1 つを
 * 話者の分離つきで送る(区間は話者の分離を頼んだときだけ返る = 設計/業務ツール/会議/文字起こしと議事録.md の 4.2)。
 * 保存しない指定は laravel/ai の Gemini の要求が既定で付ける(store = false。設計/AI/データと失敗と検査.md 6 節)。
 * 送る型は区切りのファイルの中身から推す(mp3 = audio/mpeg)。接続先とモデルは実行に写した値、言語と時間の上限は種類の設定。
 *
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 */
class TranscriptionClient
{
    /**
     * @param  TranscriptionKindSettings  $kind
     *
     * @throws LlmCallFailed 呼び出しに失敗したとき(時間の上限 = timeout、ほか = provider)
     */
    public function call(string $chunkPath, string $provider, string $model, array $kind): ChunkTranscript
    {
        try {
            $response = Transcription::fromPath($chunkPath)
                ->language($kind['language'])
                ->diarize()
                ->timeout($kind['timeout_seconds'])
                ->generate($provider, $model);
        } catch (Throwable $exception) {
            throw LlmCallFailed::from($exception);
        }

        $segments = [];

        foreach ($response->segments as $segment) {
            $segments[] = new TranscribedSegment(
                $segment->text,
                $segment->speaker,
                $segment->startSeconds,
                $segment->endSeconds,
            );
        }

        return new ChunkTranscript($segments, $response->usage->inputTokens, $response->usage->outputTokens);
    }
}
