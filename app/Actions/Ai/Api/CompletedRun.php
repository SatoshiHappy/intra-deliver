<?php

namespace App\Actions\Ai\Api;

use App\Support\AccessContext;

/**
 * 終わった AI の実行の結果({@see PromptKind::complete()} に渡す)。
 */
final readonly class CompletedRun
{
    /**
     * @param  int  $runId  実行(ai_runs.id)
     * @param  string  $kindCode  種類のコード
     * @param  int|null  $recordId  対象のレコード(依頼のときに渡したもの。消されていれば null)
     * @param  int  $requesterUserId  依頼者(users.id)
     * @param  string  $text  応答の文字列(構造化出力の JSON の文字列)
     * @param  array<array-key, mixed>  $structured  構造化出力(種類の形 {@see PromptKind::schema()} の配列。接続先が形に沿わなければ空のことがある)
     * @param  AccessContext  $requester  依頼者の判定の入力(worker の中の 2 段目の依頼に使う = docs の 設計/AI/公開の口.md 3.2)
     */
    public function __construct(
        public int $runId,
        public string $kindCode,
        public ?int $recordId,
        public int $requesterUserId,
        public string $text,
        public array $structured,
        public AccessContext $requester,
    ) {}
}
