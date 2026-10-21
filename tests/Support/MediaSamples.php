<?php

namespace Tests\Support;

use InvalidArgumentException;

/**
 * 音声・動画の形式の見本(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 3 節)。再生できるファイルではなく、
 * PHP の fileinfo がその型と推す最小のバイト列をテストの中で組む(リポジトリに音声・動画のファイルを足さない)。
 * キー = 見本の種類。推される型は経過の「形式の見本の実測」の表のとおり(実測を MediaSamplesTest が守る)。
 */
final class MediaSamples
{
    /** 見本の種類 → 推される型 */
    public const MIMES = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/x-wav',
        'm4a' => 'audio/x-m4a',
        'm4a-mp4' => 'audio/mp4',
        'mp4' => 'video/mp4',
        'mp4-audio' => 'audio/mp4',
        'webm' => 'video/webm',
    ];

    public static function has(string $kind): bool
    {
        return array_key_exists($kind, self::MIMES);
    }

    public static function bytes(string $kind): string
    {
        return match ($kind) {
            'mp3' => "ID3\x03\x00\x00\x00\x00\x00\x00\xFF\xFB\x90\x44".str_repeat("\x00", 64),
            'wav' => self::wav(),
            'm4a' => self::mp4Box('M4A '),
            'm4a-mp4', 'mp4-audio' => self::mp4Box('M4B '),
            'mp4' => self::mp4Box('isom'),
            'webm' => self::webm(),
            default => throw new InvalidArgumentException("音声・動画の見本が無い: {$kind}"),
        };
    }

    /**
     * 8kHz・16bit・モノラルで中身が 0 バイトの WAV(RIFF の見出しと fmt・data の部分)。
     */
    private static function wav(): string
    {
        $format = pack('vvVVvv', 1, 1, 8000, 16000, 2, 16);

        return 'RIFF'.pack('V', 36).'WAVEfmt '.pack('V', 16).$format.'data'.pack('V', 0);
    }

    /**
     * ftyp(ブランド)と空の moov だけの MP4 系の箱。ブランドが M4A なら audio/x-m4a、M4B なら audio/mp4、isom なら video/mp4 と推される。
     */
    private static function mp4Box(string $brand): string
    {
        $ftyp = $brand."\x00\x00\x00\x00".$brand.'isom';
        $moov = self::box('moov', self::box('mvhd', str_repeat("\x00", 100)));

        return self::box('ftyp', $ftyp).$moov;
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }

    /**
     * EBML の見出し(DocType = webm)だけの WebM。
     */
    private static function webm(): string
    {
        $docType = "\x42\x82\x84webm";
        $body = "\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08".$docType;

        return "\x1A\x45\xDF\xA3".chr(0x80 | strlen($body)).$body.str_repeat("\x00", 100);
    }
}
