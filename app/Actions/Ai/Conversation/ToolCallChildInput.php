<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\PriorToolCall;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Enums\Ai\AiToolCallDecision;
use JsonException;
use LogicException;

/**
 * 並べて動かす組の子の処理に標準入力で渡す物(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4): 実行の id・呼び出しの id と道具の
 * 名前と引数・道具の行の並びの値(ステップの行と呼び出しの順と、組の前の同じステップの呼び出し)・実行の始めの値(渡せる範囲)。依頼者と判定の
 * 主体は渡さず、子が実行の id から作り直す。
 */
final readonly class ToolCallChildInput
{
    /**
     * @param  list<PriorToolCall>  $prior  組の前の同じステップの呼び出し(組の中のほかの呼び出しは入らない)
     */
    public function __construct(
        public int $runId,
        public int $stepId,
        public int $position,
        public ModelToolCall $call,
        public array $prior,
        public string $toolName,
        public AgentToolScope $scope,
    ) {}

    /**
     * @param  list<PriorToolCall>  $prior
     */
    public static function of(
        int $runId,
        int $stepId,
        int $position,
        ModelToolCall $call,
        array $prior,
        GrantedTool $granted,
    ): self {
        return new self($runId, $stepId, $position, $call, $prior, $granted->tool->name(), $granted->scope);
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode([
            'run_id' => $this->runId,
            'step_id' => $this->stepId,
            'position' => $this->position,
            'call' => ['id' => $this->call->id, 'name' => $this->call->name, 'arguments' => $this->call->arguments],
            'prior' => array_map(self::priorArray(...), $this->prior),
            'tool_name' => $this->toolName,
            'scope' => [
                'modules' => $this->scope->modules,
                'shown' => $this->scope->shown,
                'row_access_defaults' => $this->scope->rowAccessDefaults,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws JsonException|LogicException 形が合わないとき(親が組んだ物なので作りの誤り)
     */
    public static function fromJson(string $json): self
    {
        $input = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($input) || ! is_array($input['call'] ?? null) || ! is_array($input['scope'] ?? null)) {
            throw new LogicException('子の処理の入力の形が合わない');
        }

        $call = $input['call'];
        $scope = $input['scope'];

        return new self(
            (int) $input['run_id'],
            (int) $input['step_id'],
            (int) $input['position'],
            new ModelToolCall((string) $call['id'], (string) $call['name'], (array) $call['arguments']),
            array_map(self::priorOf(...), array_values(array_filter((array) $input['prior'], is_array(...)))),
            (string) $input['tool_name'],
            new AgentToolScope(
                (array) $scope['modules'],
                (array) $scope['shown'],
                (array) $scope['row_access_defaults'],
            ),
        );
    }

    /**
     * 前の呼び出しの写し(子との受け渡しの形)。
     *
     * @return array{call_id: string, tool_name: string, arguments: array<string, mixed>|null, decision: string}
     */
    public static function priorArray(PriorToolCall $prior): array
    {
        return [
            'call_id' => $prior->callId,
            'tool_name' => $prior->toolName,
            'arguments' => $prior->arguments,
            'decision' => $prior->decision->value,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $prior  {@see self::priorArray()} の写し
     */
    public static function priorOf(array $prior): PriorToolCall
    {
        return new PriorToolCall(
            (string) $prior['call_id'],
            (string) $prior['tool_name'],
            is_array($prior['arguments'] ?? null) ? $prior['arguments'] : null,
            AiToolCallDecision::from((string) $prior['decision']),
        );
    }
}
