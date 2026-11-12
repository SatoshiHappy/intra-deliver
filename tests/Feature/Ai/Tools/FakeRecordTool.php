<?php

namespace Tests\Feature\Ai\Tools;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\PriorToolCall;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * テストの中だけの偽の道具(読む・作る・直すを性質の値で分ける)。引数の形の既定 = 業務モジュール(必須)・id・札の列。引数の形・形の外の検証・
 * 実行・呼び出しが指す業務モジュールは関数で差し替え、受けた前の呼び出しと実行の回数を残す。汎用か製品かは置いた名前空間で決まる({@see FakeTools::NAMESPACES})。
 */
abstract class FakeRecordTool implements AgentRecordTool
{
    /** @var list<list<PriorToolCall>> 形の外の検証が受けた前の呼び出しの並び(呼ばれた順) */
    public array $validatedWith = [];

    public int $executions = 0;

    /**
     * @param  list<string>  $aliases
     * @param  list<string>|null  $targets
     * @param  (Closure(array<string, mixed>, AgentToolScope, list<PriorToolCall>): ?string)|null  $validator
     * @param  (Closure(array<string, mixed>, AgentToolSubject, AgentToolScope): AgentToolResult)|null  $executor
     * @param  (Closure(array<string, mixed>): ?string)|null  $module
     * @param  (Closure(JsonSchema): array<string, Type>)|null  $shape  引数の形(null = 業務モジュール・id・札の列)
     */
    public function __construct(
        public string $name,
        public bool $readOnly = true,
        public ?AiToolAction $action = AiToolAction::View,
        public ?array $targets = null,
        public array $aliases = [],
        public string $displayName = '偽の道具',
        public ?int $maxLength = null,
        public AiInstructionTemplate $largeTemplate = AiInstructionTemplate::LargeResultRead,
        public ?Closure $validator = null,
        public ?Closure $executor = null,
        public ?Closure $module = null,
        public ?Closure $shape = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function aliases(): array
    {
        return $this->aliases;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function description(AgentToolScope $scope): string
    {
        return '業務モジュール '.implode('・', array_column($scope->modules, 'name')).' を扱う';
    }

    public function schema(JsonSchema $schema): array
    {
        if ($this->shape !== null) {
            return ($this->shape)($schema);
        }

        return [
            'module' => $schema->string()->required(),
            'id' => $schema->integer(),
            'tags' => $schema->array()->items($schema->string()),
        ];
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
        return $this->targets;
    }

    public function validate(array $arguments, AgentToolScope $scope, array $priorCalls): ?string
    {
        $this->validatedWith[] = $priorCalls;

        return $this->validator === null ? null : ($this->validator)($arguments, $scope, $priorCalls);
    }

    public function maxResultLength(): ?int
    {
        return $this->maxLength;
    }

    public function largeResultTemplate(): AiInstructionTemplate
    {
        return $this->largeTemplate;
    }

    public function execute(array $arguments, AgentToolSubject $subject, AgentToolScope $scope): AgentToolResult
    {
        $this->executions++;

        return $this->executor === null
            ? new AgentToolResult(['module' => $arguments['module'] ?? null, 'done' => true])
            : ($this->executor)($arguments, $subject, $scope);
    }

    public function moduleOf(array $arguments): ?string
    {
        if ($this->module !== null) {
            return ($this->module)($arguments);
        }

        return is_string($arguments['module'] ?? null) ? $arguments['module'] : null;
    }
}
