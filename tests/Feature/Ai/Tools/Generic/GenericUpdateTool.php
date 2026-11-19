<?php

namespace Tests\Feature\Ai\Tools\Generic;

use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Actions\Ai\Api\RecordCurrentValues;
use Closure;
use Tests\Feature\Ai\Tools\FakeRecordTool;

/**
 * 汎用の名前空間に見立てた偽の直す道具(頭 = ai_)。1 段目と 2 段目は関数で差し替え(既定 = 空の今の値と、受けた引数を書く値に置いた確認の内容)、
 * 受けた物を残す。
 */
final class GenericUpdateTool extends FakeRecordTool implements AgentUpdateTool
{
    /** @var (Closure(array<string, mixed>, AgentToolSubject, AgentToolScope): ?RecordCurrentValues)|null */
    public ?Closure $reader = null;

    /** 1 段目が呼ばれた回数 */
    public int $reads = 0;

    /**
     * 2 段目が受けた物(呼ばれた順)。
     *
     * @var list<array{current: RecordCurrentValues, arguments: array<string, mixed>, parties: AgentToolParties}>
     */
    public array $confirmedWith = [];

    /** @var (Closure(array<string, mixed>, string): AgentToolResult)|null */
    public ?Closure $writer = null;

    /** @var list<array{written: array<string, mixed>, version: string}> 書くが受けた物(呼ばれた順) */
    public array $writtenWith = [];

    public function current(array $arguments, AgentToolSubject $subject, AgentToolScope $scope): ?RecordCurrentValues
    {
        $this->reads++;

        return $this->reader === null
            ? new RecordCurrentValues((int) ($arguments['id'] ?? 0), '今のレコード', [], [], [
                'restricted' => false,
                'viewers' => [],
            ], 'v1')
            : ($this->reader)($arguments, $subject, $scope);
    }

    public function confirmation(
        RecordCurrentValues $current,
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): RecordConfirmation {
        $this->confirmedWith[] = ['current' => $current, 'arguments' => $arguments, 'parties' => $parties];

        return new RecordConfirmation(['id' => $current->id, 'next' => $arguments]);
    }

    public function write(
        array $written,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        RecordConfirmation $confirmation,
        string $version,
    ): AgentToolResult {
        $this->writtenWith[] = ['written' => $written, 'version' => $version];
        $module = is_string($written['module'] ?? null) ? $written['module'] : '';
        $id = is_int($written['id'] ?? null) ? $written['id'] : 0;

        return $this->writer === null
            ? new AgentToolResult(['written' => true], [['module' => $module, 'id' => $id, 'name' => '直した']])
            : ($this->writer)($written, $version);
    }
}
