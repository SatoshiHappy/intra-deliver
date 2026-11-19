<?php

namespace Tests\Feature\Ai\Tools\Generic;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordConfirmation;
use Closure;
use Tests\Feature\Ai\Tools\FakeRecordTool;

/**
 * 汎用の名前空間に見立てた偽の作る道具(頭 = ai_)。確認の内容と書くは関数で差し替え(既定 = 受けた引数を書く値に置く・書いた件の id は
 * 書いた順の番号)、受けた物を残す。
 */
final class GenericCreateTool extends FakeRecordTool implements AgentCreateTool
{
    /**
     * @var (Closure(array<string, mixed>, AgentToolSubject, AgentToolScope, AgentToolParties): RecordConfirmation)|null
     */
    public ?Closure $confirmer = null;

    /** @var list<array{arguments: array<string, mixed>, parties: AgentToolParties}> 確認の内容を作る口が受けた物(呼ばれた順) */
    public array $confirmedWith = [];

    /** @var (Closure(array<string, mixed>): AgentToolResult)|null */
    public ?Closure $writer = null;

    /** @var list<array<string, mixed>> 書くが受けた書く値の引数(呼ばれた順) */
    public array $writtenWith = [];

    public function confirmation(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): RecordConfirmation {
        $this->confirmedWith[] = ['arguments' => $arguments, 'parties' => $parties];

        return $this->confirmer === null
            ? new RecordConfirmation(['next' => $arguments])
            : ($this->confirmer)($arguments, $subject, $scope, $parties);
    }

    public function write(
        array $written,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        RecordConfirmation $confirmation,
    ): AgentToolResult {
        $this->writtenWith[] = $written;
        $module = is_string($written['module'] ?? null) ? $written['module'] : '';
        $id = count($this->writtenWith);

        return $this->writer === null
            ? new AgentToolResult(['written' => true], [['module' => $module, 'id' => $id, 'name' => "作った {$id}"]])
            : ($this->writer)($written);
    }
}
