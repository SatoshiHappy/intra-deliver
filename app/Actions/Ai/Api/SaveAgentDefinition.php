<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\Conversation\AgentDefinitionValidator;
use App\Models\Ai\AiAgentDefinition;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * エージェントの定義を作る・直す(docs の 設計/AI/設定とプロンプト/README.md 11.2)。主体の判定をしない(呼べる場所は機械検査 (b) の一覧で絞る)。
 * 同時の保存は後の保存が勝つ(版を持たない = 11.1)。
 */
final readonly class SaveAgentDefinition
{
    public function __construct(private AgentDefinitionValidator $problems) {}

    /**
     * @return int 作った定義の id
     *
     * @throws ValidationException 合わない欄があるとき(欄ごとの理由をすべて返す)
     */
    public function create(AgentDefinitionInput $input): int
    {
        $this->check($input);

        return AiAgentDefinition::query()->create(self::values($input))->id;
    }

    /**
     * @throws ModelNotFoundException<AiAgentDefinition> 定義が無いとき
     * @throws ValidationException 合わない欄があるとき
     */
    public function update(int $id, AgentDefinitionInput $input): void
    {
        $definition = AiAgentDefinition::query()->findOrFail($id);
        $this->check($input);
        $definition->update(self::values($input));
    }

    private function check(AgentDefinitionInput $input): void
    {
        $problems = $this->problems->of($input);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /** @return array{name: string, instructions: string, tools: list<string>, knowledge: list<string>} */
    private static function values(AgentDefinitionInput $input): array
    {
        return [
            'name' => $input->name,
            'instructions' => $input->instructions,
            'tools' => $input->tools,
            'knowledge' => $input->knowledge,
        ];
    }
}
