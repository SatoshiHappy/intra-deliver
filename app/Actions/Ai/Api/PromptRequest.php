<?php

namespace App\Actions\Ai\Api;

/**
 * AI の実行の依頼({@see RequestPrompt} の入力)。
 * 送る文は呼び手が依頼者の権限で読んだデータから組み、読んだレコードの id を出典に渡す(設計/AI/データと失敗と検査.md 6 節)。
 */
final readonly class PromptRequest
{
    /**
     * @param  string  $kindCode  種類のコード({@see PromptKind::code()})
     * @param  int|null  $recordId  対象のレコード(任意。会議など)
     * @param  list<int>  $sourceRecordIds  AI に送ったデータを読んだレコードの id
     * @param  string  $systemText  システムの文(指示)
     * @param  string  $userText  利用者の文(送るデータ)
     */
    public function __construct(
        public string $kindCode,
        public ?int $recordId,
        public array $sourceRecordIds,
        public string $systemText,
        public string $userText,
    ) {}
}
