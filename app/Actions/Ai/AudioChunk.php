<?php

namespace App\Actions\Ai;

/**
 * 変換した音声を区切ったものの 1 つ(内部。{@see AudioConverter::split()} の結果)。時刻は元の音声の始まりからの秒。
 * 終わりの無音と全体が無音かは、応答が区切りの終わりまで届いたかの判定に使う(docs の 設計/AI/実行と費用.md 4.2)。
 */
final readonly class AudioChunk
{
    /**
     * @param  string  $path  区切りのファイル(変換の形)
     * @param  float  $startSeconds  始まりの秒
     * @param  float  $lengthSeconds  長さ(秒)
     * @param  float  $trailingSilenceSeconds  終わりの無音の長さ(秒。無ければ 0)
     * @param  bool  $isSilent  区切りの全体が無音か
     */
    public function __construct(
        public string $path,
        public float $startSeconds,
        public float $lengthSeconds,
        public float $trailingSilenceSeconds,
        public bool $isSilent,
    ) {}
}
