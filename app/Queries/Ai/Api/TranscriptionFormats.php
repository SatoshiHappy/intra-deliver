<?php

namespace App\Queries\Ai\Api;

use App\Queries\Upload\Api\UploadLimits;

/**
 * 文字起こしが受ける音声の形式(添付の形式の表の音声 = docs の 設計/業務ツール/会議/文字起こしと議事録.md の 4.3)を答える 1 か所。
 * 文字起こしの依頼の口と、呼び手(音声を選ぶ・拡張子で出どころを分ける)が同じ定義を読む。型は添付の土台の対応表から組む(写さない)。
 * 主体の判定をしない(形式の規則は利用者によらない)。
 */
class TranscriptionFormats
{
    /** 受ける音声の拡張子(小文字) */
    private const EXTENSIONS = ['mp3', 'm4a', 'wav'];

    public function __construct(private UploadLimits $limits) {}

    /**
     * @return list<string> 受ける音声の拡張子(小文字)
     */
    public function extensions(): array
    {
        return self::EXTENSIONS;
    }

    /**
     * 受ける音声の型(添付の対応表のうち、受ける拡張子の行の型)。
     *
     * @return list<string>
     */
    public function mimes(): array
    {
        $formats = array_intersect_key($this->limits->formats(), array_flip(self::EXTENSIONS));

        return array_values(array_unique(array_merge(...array_values($formats))));
    }

    /**
     * 名前の拡張子(大文字小文字を区別しない)が受ける音声の拡張子か。
     */
    public function isAudioName(string $name): bool
    {
        $dot = strrpos($name, '.');
        $extension = $dot === false ? '' : mb_strtolower(substr($name, $dot + 1), 'UTF-8');

        return in_array($extension, self::EXTENSIONS, true);
    }

    /**
     * 名前と中身から推した型が、受ける音声の形式か(拡張子が受ける音声で、型が添付の対応表のその行に当たる)。
     */
    public function accepts(string $name, string $mime): bool
    {
        return $this->isAudioName($name) && $this->limits->accepts($name, $mime);
    }
}
