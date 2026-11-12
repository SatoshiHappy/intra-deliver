<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolScope;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * 道具の名前空間で、継いだ interface(AgentRecordTool)を通して約束を実装する基底のクラス(値の型と列挙への依存は通る)
 */
abstract class RecordToolBase implements AgentRecordTool
{
    public function aliases(): array
    {
        return [];
    }

    public function description(AgentToolScope $scope): string
    {
        return '業務モジュール '.implode('・', $scope->codes()).' のレコードを読む';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['module' => $schema->string()->required(), 'id' => $schema->integer()->required()];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function requiredAction(): ?AiToolAction
    {
        return AiToolAction::View;
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

    public function moduleOf(array $arguments): ?string
    {
        $module = $arguments['module'] ?? null;

        return is_string($module) ? $module : null;
    }
}
