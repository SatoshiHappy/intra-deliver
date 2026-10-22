<?php

namespace App\Actions\Ai\Api;

/**
 * 文字起こしの区間 1 つ({@see CompletedTranscription} の一覧の要素)。時刻は音声 1 本の始まりからの秒(区切りの始まりの分をずらした後)。
 * 区切りが 2 つ以上のとき、話者のラベルは区切りの番号を添えた形になる(docs の 設計/AI/実行と費用.md 4.2)。
 */
final readonly class TranscribedSegment
{
    /**
     * @param  string  $text  文
     * @param  string  $speakerLabel  話者のラベル(接続先が振る。区切りをまたいで同じ人が別のラベルになりうる)
     * @param  float  $startSeconds  開始の秒
     * @param  float  $endSeconds  終了の秒
     */
    public function __construct(
        public string $text,
        public string $speakerLabel,
        public float $startSeconds,
        public float $endSeconds,
    ) {}
}
