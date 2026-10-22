<?php

namespace App\Actions\Ai\Api;

use App\Support\AccessContext;

/**
 * 終わった AI の文字起こしの結果({@see TranscriptionKind::complete()} に渡す)。
 */
final readonly class CompletedTranscription
{
    /**
     * @param  int  $runId  実行(ai_runs.id)
     * @param  string  $kindCode  種類のコード
     * @param  int|null  $recordId  対象のレコード(今の値。消されていれば null)
     * @param  int  $requesterUserId  依頼者(users.id)
     * @param  list<TranscribedSegment>  $segments  区間の一覧(時刻の順)
     * @param  int  $chunkCount  区切りの数(2 つ以上なら、区切りごとに話者のラベルが振り直されている)
     * @param  float  $audioSeconds  音声の長さ(依頼の時点に測った秒)
     * @param  AccessContext  $requester  依頼者の判定の入力(worker の中の 2 段目の依頼に使う = docs の 設計/AI/公開の口.md 3.2)
     */
    public function __construct(
        public int $runId,
        public string $kindCode,
        public ?int $recordId,
        public int $requesterUserId,
        public array $segments,
        public int $chunkCount,
        public float $audioSeconds,
        public AccessContext $requester,
    ) {}
}
