<?php

namespace Tests\Feature\Ai\Tools\Generic;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordConfirmation;
use Closure;
use Tests\Feature\Ai\Tools\FakeRecordTool;

/**
 * 汎用の名前空間に見立てた偽の作る道具(頭 = ai_)。確認の内容は関数で差し替え(既定 = 受けた引数を書く値に置く)、受けた物を残す。
 */
final class GenericCreateTool extends FakeRecordTool implements AgentCreateTool
{
    /**
     * @var (Closure(array<string, mixed>, AgentToolSubject, AgentToolScope, AgentToolParties): RecordConfirmation)|null
     */
    public ?Closure $confirmer = null;

    /** @var list<array{arguments: array<string, mixed>, parties: AgentToolParties}> 確認の内容を作る口が受けた物(呼ばれた順) */
    public array $confirmedWith = [];

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
}
