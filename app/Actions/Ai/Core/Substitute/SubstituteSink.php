<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\Substitute\Sdk\SubstituteReading;
use Laravel\Ai\Gateway\StepResponse;

/**
 * 1 回の送信の受け先(1 回の送信は返す項目に集め、ループは知らせて送る履歴に足す)。
 */
interface SubstituteSink
{
    /**
     * @param  non-empty-list<array{type: 'text', text: string}|array{type: 'tool_call', id: string, name: string,
     *     arguments: array<string, mixed>, result_id: string|null}>  $blocks
     */
    public function confirmed(string $responseId, int $stepId, array $blocks): void;

    public function delta(int $stepId, string $text): void;

    public function responded(string $responseId, StepResponse $response, SubstituteReading $reading): void;
}
