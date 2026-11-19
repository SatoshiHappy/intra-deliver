<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use App\Actions\Ai\Core\Substitute\SentMessage;

/**
 * 応答を読む途中の受け先(確定したブロック・途中の文の差分・読む途中の確かめ = docs の 設計/AI/会話の中核の口と受け手.md 13.3)。
 *
 * @phpstan-import-type SentBlock from SentMessage
 */
interface SubstituteReader
{
    public function delta(string $text): void;

    /**
     * 同じ時に確定したブロック(空でない)。
     *
     * @param  non-empty-list<array{type: 'text', text: string}|array{type: 'tool_call', id: string, name: string,
     *     arguments: array<string, mixed>, result_id: string|null}>  $blocks
     */
    public function confirmed(array $blocks): void;

    /** 読む途中の確かめで止めるか */
    public function stopsWhileReading(): bool;
}
