<?php

namespace App\Actions\Meeting;

use Illuminate\Validation\ValidationException;

/**
 * 字幕のファイル(.vtt)を発言の行に分ける(キュー 1 つを 1 行 = docs の 設計/業務ツール/会議/README.md の 2 節)。読む範囲は WebVTT の書式
 * (先頭行・NOTE/STYLE/REGION の塊・時刻の行のキューの設定)に合わせ、壊れたキューがあればファイル全体を断る(一部だけ取り込まない)。
 */
class WebVttReader
{
    public const MALFORMED = '字幕のファイル(.vtt)の形式が正しくありません';

    /** 話者のラベルの最大の文字数(表 transcript_segments の speaker_label) */
    private const SPEAKER_MAX_LENGTH = 100;

    /** 秒の上限(表 transcript_segments の numeric(10,3))をミリ秒で */
    private const MAX_MILLISECONDS = 9_999_999_999;

    /** 時刻(時:分:秒.ミリ秒 か 分:秒.ミリ秒) */
    private const TIMESTAMP = '/\A(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})\z/D';

    /** 時刻の行(開始 --> 終わり。後ろのキューの設定は読まない) */
    private const TIMING = '/\A(\S+)[ \t]+-->[ \t]+(\S+)(?:[ \t]|\z)/D';

    /**
     * @param  string  $content  BOM を外した UTF-8 の全文
     * @param  string  $errorKey  422 のキー(取り込んだ入力の名前)
     * @return list<TranscriptLine>
     *
     * @throws ValidationException 先頭行・時刻の行が形に合わないとき、行が多すぎるとき
     */
    public function lines(string $content, string $errorKey): array
    {
        $lines = [];
        $isHeader = true;

        foreach (TextLines::blocks($content) as $block) {
            if ($isHeader) {
                $this->ensureHeader($block[0], $errorKey);
                $isHeader = false;

                continue;
            }

            $line = $this->cue($block, $errorKey);

            if ($line !== null) {
                $lines[] = $line;
                TextImportReader::ensureLineCount(count($lines), $errorKey);
            }
        }

        return $isHeader ? throw self::malformed($errorKey) : $lines;
    }

    /**
     * @throws ValidationException
     */
    private function ensureHeader(string $firstLine, string $errorKey): void
    {
        if (preg_match('/\AWEBVTT(?:[ \t]|\z)/D', $firstLine) !== 1) {
            throw self::malformed($errorKey);
        }
    }

    /**
     * 塊 1 つ(NOTE・STYLE・REGION の塊と、本文が空のキューは null)。
     *
     * @param  non-empty-list<string>  $block
     *
     * @throws ValidationException
     */
    private function cue(array $block, string $errorKey): ?TranscriptLine
    {
        if (preg_match('/\A(?:NOTE|STYLE|REGION)(?:[ \t]|\z)/D', $block[0]) === 1) {
            return null;
        }

        $timingIndex = str_contains($block[0], '-->') ? 0 : 1;
        [$started, $ended] = $this->timing($block[$timingIndex] ?? '', $errorKey);

        return $this->line(implode(' ', array_slice($block, $timingIndex + 1)), $started, $ended);
    }

    /**
     * @return array{string, string} 開始と終わりの秒(小数 3 桁の 10 進の文字列)
     *
     * @throws ValidationException
     */
    private function timing(string $line, string $errorKey): array
    {
        if (preg_match(self::TIMING, $line, $parts) !== 1) {
            throw self::malformed($errorKey);
        }

        $started = $this->milliseconds($parts[1], $errorKey);
        $ended = $this->milliseconds($parts[2], $errorKey);

        if ($ended < $started) {
            throw self::malformed($errorKey);
        }

        return [self::seconds($started), self::seconds($ended)];
    }

    /**
     * @throws ValidationException
     */
    private function milliseconds(string $timestamp, string $errorKey): int
    {
        $hours = preg_match(self::TIMESTAMP, $timestamp, $parts) === 1 ? ltrim($parts[1], '0') : null;

        // 時の桁が多いと整数に収まらないので、上限(約 2,777 時間)を超える桁は数える前に断る
        if ($hours === null || strlen($hours) > 4) {
            throw self::malformed($errorKey);
        }

        $milliseconds = ((((int) $hours * 60 + (int) $parts[2]) * 60) + (int) $parts[3]) * 1000 + (int) $parts[4];

        return $milliseconds > self::MAX_MILLISECONDS ? throw self::malformed($errorKey) : $milliseconds;
    }

    /**
     * キューの本文から発言の行を作る。最初の <v 名前>(<v.クラス 名前> を含む)の名前を話者のラベルにし、タグを除いて文字参照を戻す。
     */
    private function line(string $cueText, string $started, string $ended): ?TranscriptLine
    {
        $label = preg_match('/<v(?:\.[^ \t>]*)?[ \t]+([^>]*)>/', $cueText, $parts) === 1
            ? mb_substr(TextLines::trim(self::decoded($parts[1])), 0, self::SPEAKER_MAX_LENGTH)
            : '';
        $text = TextLines::trim(self::decoded((string) preg_replace('/<[^>]*>/', '', $cueText)));

        return $text === '' ? null : new TranscriptLine($text, $label === '' ? null : $label, $started, $ended);
    }

    private static function decoded(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function seconds(int $milliseconds): string
    {
        return sprintf('%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000);
    }

    private static function malformed(string $errorKey): ValidationException
    {
        return ValidationException::withMessages([$errorKey => self::MALFORMED]);
    }
}
