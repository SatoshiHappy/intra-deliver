<?php

namespace App\Actions\Ai\Api;

/**
 * AI の文字起こしの依頼(文字起こしの依頼の口の入力)。音声は対象のレコードの添付で、閲覧と添付であることの判定は呼び手が行う
 * (docs の 設計/AI/公開の口.md 3 節の「呼び手が守ること」)。言語は種類の設定から取る(値の置き場を 1 つにするため)。
 */
final readonly class TranscriptionRequest
{
    /**
     * @param  string  $kindCode  種類のコード({@see TranscriptionKind::code()})
     * @param  int  $recordId  対象のレコード(音声を添付に持つレコード)
     * @param  int  $uploadId  音声(対象のレコードの添付の実体。uploads.id)
     */
    public function __construct(
        public string $kindCode,
        public int $recordId,
        public int $uploadId,
    ) {}
}
