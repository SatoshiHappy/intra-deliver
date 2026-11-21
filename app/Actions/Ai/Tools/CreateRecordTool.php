<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use LogicException;

/**
 * 汎用の道具「作る」(docs の 設計/AI/道具と権限/README.md 10.2 の作るの行)。呼ばれた時は確認の内容を作るだけで書かない(判定の関数が問う =
 * 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B5)。書くのは利用者が確認の後(10.5)。後の件が指す仮の名前を `ref` に書ける。
 */
final class CreateRecordTool implements AgentCreateTool
{
    public function __construct(
        private readonly RecordConfirmations $confirmations,
        private readonly RecordToolWrites $writes,
    ) {}

    public function name(): string
    {
        return 'ai_create_record';
    }

    public function aliases(): array
    {
        return [];
    }

    public function displayName(): string
    {
        return '作成';
    }

    public function description(AgentToolScope $scope): string
    {
        return RecordWriteToolDescriptions::create($scope);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...RecordWriteSchema::common($schema),
            'ref' => $schema->string()->description(RecordWriteToolDescriptions::ARGUMENTS['ref']),
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isConcurrencySafe(): bool
    {
        return false;
    }

    public function requiredAction(): AiToolAction
    {
        return AiToolAction::Create;
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
        return RecordWriteSchema::moduleOf($arguments['module'] ?? null);
    }

    public function partyArguments(): array
    {
        return RecordWriteSchema::PARTIES;
    }

    public function confirmation(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): RecordConfirmation {
        return $this->confirmations->confirmation($arguments, $subject, $scope, $parties, null);
    }

    /**
     * @throws LogicException 書く値の引数に業務モジュールが無いとき(包みの形の検証を通った引数には必ずある)
     */
    public function write(
        array $written,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        RecordConfirmation $confirmation,
    ): AgentToolResult {
        $module = $this->moduleOf($written) ?? throw new LogicException('作るの書く値の引数に業務モジュールが無い');
        $id = $this->writes->create($subject, $module, $written);
        $definition = $scope->modules[$module]['definition'] ?? [];

        return RecordToolWrites::result($module, $id, $written, $definition, null);
    }

    /**
     * @throws LogicException いつも(呼ばれた時の判定は問うを返し、許すを返さない。書くのは確認の後の書く時の部品)
     */
    public function execute(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): AgentToolResult {
        throw new LogicException('作るは呼ばれた時に書かない(書くのは確認の後)');
    }
}
