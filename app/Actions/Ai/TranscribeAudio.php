<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\TranscribedSegment;
use App\Enums\Ai\AiRunErrorCode;
use App\Models\Ai\AiRun;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\TranscriptionSettings;
use App\Queries\Upload\Api\UploadQuery;
use RuntimeException;
use Throwable;

/**
 * 実行の音声を変換して区切り、区切りごとに順に文字起こしを呼んでつなぐ(内部。トランザクションの外で動く = docs の
 * 設計/AI/実行と費用.md 4 節の文字起こしの種類の 2、4.2)。区切りごとに応答が終わりまで届いたかを確かめ、区間の時刻を区切りの始まりの分ずらし、
 * 区切りが 2 つ以上なら話者のラベルに区切りの番号を添える(違う区切りの別の人が同じラベルにならないように)。
 *
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 */
class TranscribeAudio
{
    /** 話者のラベルの長さの上限(呼び手の書き起こしの列 transcript_segments.speaker_label の長さ) */
    private const SPEAKER_LABEL_MAX = 100;

    public function __construct(
        private UploadQuery $uploads,
        private AudioConverter $converter,
        private TranscriptionClient $client,
        private RunCosts $costs,
        private TranscriptionSettings $settings,
    ) {}

    /**
     * @param  TranscriptionKindSettings  $kind
     * @param  string  $directory  区切りの一時ファイルの置き場(消すのは呼び手)
     *
     * @throws TranscriptionStopped 変換の失敗・音声の実体が無い(conversion)、接続先の誤り(provider)・時間切れ(timeout)、
     *                              応答が区切りの終わりまで届かない(incomplete)とき
     */
    public function execute(AiRun $run, array $kind, string $directory): TranscriptionOutcome
    {
        $chunks = $this->chunks($run, $kind, $directory);
        $segments = [];
        $calledSeconds = 0.0;
        $tokens = [0, 0];

        foreach ($chunks as $index => $chunk) {
            $calledSeconds += $chunk->lengthSeconds;

            try {
                $transcript = $this->client->call($chunk->path, $run->provider, $run->model, $kind);
            } catch (LlmCallFailed $failure) {
                throw $this->stopped($run, $failure->errorCode, $failure, $calledSeconds, null, $segments);
            }

            $tokens = [$tokens[0] + $transcript->inputTokens, $tokens[1] + $transcript->outputTokens];
            $segments = [...$segments, ...self::shifted($transcript->segments, $chunk, $index + 1, count($chunks))];

            if (! $this->reachesEnd($transcript, $chunk)) {
                $incomplete = new RuntimeException(sprintf(
                    '文字起こしの応答が区切り %d / %d の終わりまで届いていない(区切りの長さ %.3f 秒)',
                    $index + 1,
                    count($chunks),
                    $chunk->lengthSeconds,
                ));

                throw $this->stopped($run, AiRunErrorCode::Incomplete, $incomplete, $calledSeconds, $tokens, $segments);
            }
        }

        return new TranscriptionOutcome(
            $segments,
            count($chunks),
            $this->result($run, $calledSeconds, $tokens, self::json($segments)),
        );
    }

    /**
     * 音声の実体を変換して区切る(実体が無い = 処理中にレコードが消された・期限で消された、も変換の失敗 = 設計/AI/データと失敗と検査.md 7 節)。
     *
     * @param  TranscriptionKindSettings  $kind
     * @return list<AudioChunk>
     *
     * @throws TranscriptionStopped 変換できないとき(費用は 0)
     */
    private function chunks(AiRun $run, array $kind, string $directory): array
    {
        $path = $run->input_upload_id === null ? null : $this->uploads->pathOf($run->input_upload_id);

        try {
            if ($path === null || ! is_file($path)) {
                $original = $run->original_input_upload_id;

                throw new AudioConversionFailed("AI の実行 {$run->id} の音声の実体が無い(元の添付 {$original})");
            }

            return $this->converter->split($path, $directory, $kind['chunk_seconds']);
        } catch (AudioConversionFailed $failure) {
            throw $this->stopped($run, AiRunErrorCode::Conversion, $failure, 0.0, null, []);
        }
    }

    /**
     * 区切りの応答が区切りの終わり(終わりの無音と許す差を除く)まで届いたか。区間が 0 なら区切りの始まりを最後の終わりとして同じ式で見る
     * (全体が無音の区切りは終わりの無音が区切りの長さなので届いたことになる = 設計/AI/実行と費用.md 4.2)。
     */
    private function reachesEnd(ChunkTranscript $transcript, AudioChunk $chunk): bool
    {
        $segments = $transcript->segments;
        $lastEnd = $segments === [] ? 0.0 : $segments[count($segments) - 1]->endSeconds;
        $tolerance = $this->settings->incompleteToleranceSeconds();

        return $lastEnd >= $chunk->lengthSeconds - $chunk->trailingSilenceSeconds - $tolerance;
    }

    /**
     * @param  array{int, int}|null  $tokens  呼んだ区切りのすべてが応答を返したときのトークンの和(分からなければ null)
     * @param  list<TranscribedSegment>  $segments  分かった分の区間
     */
    private function stopped(
        AiRun $run,
        AiRunErrorCode $errorCode,
        Throwable $failure,
        float $calledSeconds,
        ?array $tokens,
        array $segments,
    ): TranscriptionStopped {
        $reason = $errorCode === AiRunErrorCode::Incomplete ? FailedRun::OUTPUT_TRUNCATED : null;
        $output = $calledSeconds > 0 ? self::json($segments) : null;

        $result = $this->result($run, $calledSeconds, $tokens, $output, $reason);

        return new TranscriptionStopped($errorCode, $failure, $result);
    }

    /**
     * 呼んだ区切りの長さの和で数えた費用と、使用量・原文(5.1)。
     *
     * @param  array{int, int}|null  $tokens
     */
    private function result(
        AiRun $run,
        float $seconds,
        ?array $tokens,
        ?string $output,
        ?string $incompleteReason = null,
    ): RunResult {
        $costs = $this->costs;

        return $costs->transcriptionResult($run->provider, $run->model, $seconds, $tokens, $output, $incompleteReason);
    }

    /**
     * 区切りの区間を音声 1 本の時刻にずらし、区切りが 2 つ以上なら話者のラベルを「<区切りの番号>-<接続先のラベル>」にする。
     *
     * @param  list<TranscribedSegment>  $segments
     * @return list<TranscribedSegment>
     */
    private static function shifted(array $segments, AudioChunk $chunk, int $number, int $count): array
    {
        return array_map(fn (TranscribedSegment $segment): TranscribedSegment => new TranscribedSegment(
            $segment->text,
            $count > 1 ? self::numbered($number, $segment->speakerLabel) : $segment->speakerLabel,
            $chunk->startSeconds + $segment->startSeconds,
            $chunk->startSeconds + $segment->endSeconds,
        ), $segments);
    }

    private static function numbered(int $number, string $label): string
    {
        return mb_substr("{$number}-{$label}", 0, self::SPEAKER_LABEL_MAX);
    }

    /**
     * 原文(ai_run_texts.output_text)= ずらした後の区間の一覧の JSON。
     *
     * @param  list<TranscribedSegment>  $segments
     */
    private static function json(array $segments): string
    {
        return json_encode(array_map(fn (TranscribedSegment $segment): array => [
            'text' => $segment->text,
            'speaker_label' => $segment->speakerLabel,
            'start_seconds' => $segment->startSeconds,
            'end_seconds' => $segment->endSeconds,
        ], $segments), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
