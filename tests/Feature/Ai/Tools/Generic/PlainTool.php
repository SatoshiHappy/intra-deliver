<?php

namespace Tests\Feature\Ai\Tools\Generic;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** 呼び出しが指す業務モジュールを答えない偽の道具(AgentRecordTool でない。性質は渡した値) */
final class PlainTool implements AgentTool
{
    public function __construct(private string $name, private bool $readOnly, private ?AiToolAction $action) {}

    public function name(): string
    {
        return $this->name;
    }

    public function aliases(): array
    {
        return [];
    }

    public function displayName(): string
    {
        return '答えない道具';
    }

    public function description(AgentToolScope $scope): string
    {
        return '答えない道具';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function requiredAction(): ?AiToolAction
    {
        return $this->action;
    }

    public function targetModules(): ?array
    {
        return null;
    }

    public function validate(array $arguments, AgentToolScope $scope, array $priorCalls): ?string
    {
        return null;
    }

    public function maxResultLength(): ?int
    {
        return null;
    }

    public function largeResultTemplate(): AiInstructionTemplate
    {
        return AiInstructionTemplate::LargeResultTruncated;
    }

    public function execute(array $arguments, AgentToolSubject $subject, AgentToolScope $scope): AgentToolResult
    {
        return new AgentToolResult(['done' => true]);
    }
}
