<?php

namespace App\Actions\Meeting;

use App\Enums\Meeting\TranscriptSource;
use App\Models\Meeting\Transcript;
use App\Models\Meeting\TranscriptSegment;
use Illuminate\Support\Facades\Date;

/**
 * 書き起こしと発言の行を書く 1 か所(テキストの作成・文字起こしの完了と、公開デモの見本の会議の初期データが呼ぶ = docs の 設計/業務ツール/会議/README.md の 2 節、
 * 口と検査.md の 12 節)。書き起こしは直せないので、直す・消す業務処理は持たない(会議の削除の連鎖でだけ消える)。
 * 呼び手のトランザクションの中で呼ぶ(会議のレコードと同じ確定にする)。
 */
class MeetingTranscriptWriter
{
    /** 書き起こしの言語(今は日本語だけ) */
    public const LANGUAGE = 'ja';

    /** 発言の行を 1 回の INSERT で書く数 */
    private const CHUNK_SIZE = 1000;

    /**
     * @param  string|null  $fileName  取り込んだファイルの名前(貼り付けは null)
     * @param  list<TranscriptLine>  $lines  発言の行(並び = seq の順)
     * @param  int|null  $aiRunId  書き起こしを作った文字起こしの実行(音声のときだけ)
     * @param  int  $chunkCount  音声を区切って書き起こした数(音声のときだけ 2 以上になりうる)
     * @return int 書き起こしの id
     */
    public function write(
        int $recordId,
        TranscriptSource $source,
        ?string $fileName,
        array $lines,
        ?int $aiRunId = null,
        int $chunkCount = 1,
    ): int {
        $transcript = Transcript::query()->create([
            'record_id' => $recordId,
            'source' => $source,
            'file_name' => $fileName,
            'language' => self::LANGUAGE,
            'ai_run_id' => $aiRunId,
            'chunk_count' => $chunkCount,
        ]);
        $now = Date::now();

        foreach (array_chunk($lines, self::CHUNK_SIZE) as $chunkIndex => $chunk) {
            $rows = [];

            foreach ($chunk as $index => $line) {
                $rows[] = [
                    'transcript_id' => $transcript->id,
                    'seq' => $chunkIndex * self::CHUNK_SIZE + $index + 1,
                    'speaker_label' => $line->speakerLabel,
                    'started_at_sec' => $line->startedAtSec,
                    'ended_at_sec' => $line->endedAtSec,
                    'text' => $line->text,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            TranscriptSegment::query()->insert($rows);
        }

        return $transcript->id;
    }
}
