<?php

namespace App\Queries\Meeting;

use App\Enums\Builtin\Meeting;
use App\Models\Meeting\Transcript;
use App\Queries\Records\Api\RecordShowSections;
use App\Support\AccessContext;

/**
 * 会議の詳細のサイドパネルの「書き起こし」の節(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.3、形 = 設計/業務データの入れ物/
 * レコードの共通機能/詳細の画面/節の口.md の 1 節の lines)。書き起こしの無い会議と、会議でない業務モジュールでは出さない。
 * 閲覧の判定は呼び手(Records)が済ませてから呼ぶ。
 *
 * @phpstan-import-type RecordShowLinesSection from RecordShowSections
 */
class MeetingTranscriptSection implements RecordShowSections
{
    /** 書き起こしの区切りの数がこれ以上のときに出す頭の注(話者のラベルは区切りごとに振り直される = 文字起こしと議事録.md の 4.3) */
    private const CHUNKED_FROM = 2;

    private const CHUNKED_NOTE = '長い音声を区切って書き起こしたため、区切りごとに話者のラベルが振り直されています。';

    public function descriptor(): array
    {
        return ['code' => 'meeting-transcript', 'heading' => '書き起こし', 'shortHeading' => '発言'];
    }

    /**
     * 書き起こしの有無と発言の行の数を 1 本の問い合わせで読む(詳細を開くたびに呼ぶ)。
     */
    public function presence(AccessContext $context, string $moduleCode, int $recordId): ?array
    {
        if ($moduleCode !== Meeting::CODE) {
            return null;
        }

        $transcript = Transcript::query()->select('id')->where('record_id', $recordId)->withCount('segments')->first();

        return $transcript === null ? null : ['count' => $transcript->segments_count];
    }

    /**
     * 発言の行の全部(並び = seq)。時刻は開始の秒を切り捨てた HH:MM:SS。
     *
     * @return RecordShowLinesSection|null
     */
    public function section(AccessContext $context, string $moduleCode, int $recordId): ?array
    {
        $transcript = $this->transcriptOf($moduleCode, $recordId);

        if ($transcript === null) {
            return null;
        }

        $items = [];

        $segments = $transcript->segments()->orderBy('seq')->get(['speaker_label', 'started_at_sec', 'text']);

        foreach ($segments as $segment) {
            $items[] = [
                'time' => $segment->started_at_sec === null ? null : self::clock($segment->started_at_sec),
                'label' => $segment->speaker_label === null ? null : "話者 {$segment->speaker_label}",
                'text' => $segment->text,
            ];
        }

        return [
            'kind' => 'lines',
            'count' => count($items),
            'note' => ($transcript->chunk_count ?? 0) >= self::CHUNKED_FROM ? self::CHUNKED_NOTE : null,
            'items' => $items,
            'emptyText' => '発言はありません。',
        ];
    }

    private function transcriptOf(string $moduleCode, int $recordId): ?Transcript
    {
        return $moduleCode === Meeting::CODE
            ? Transcript::query()->where('record_id', $recordId)->first(['id', 'chunk_count'])
            : null;
    }

    /**
     * 秒(小数 3 桁までの 10 進の文字列)を HH:MM:SS にする(秒の端数は切り捨てる)。
     */
    private static function clock(string $seconds): string
    {
        $total = (int) $seconds;

        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }
}
