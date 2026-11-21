<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\AgentDefinitionInput;
use App\Actions\Ai\ToolCalls\AgentTools;
use App\Models\Ai\AiAgentDefinition;
use App\Queries\Records\Api\RecordModuleDirectory;

/**
 * 定義の保存の時の確かめ(docs の 設計/AI/設定とプロンプト/README.md 11.1 の右の欄・11.3 の保存の時)。合わない欄をすべて返す(1 つの 422 で
 * 返すため = 11.2)。
 */
final readonly class AgentDefinitionValidator
{
    /** 名前の長さの上限(文字。値の理由 = docs の 設計/AI/設定とプロンプト/README.md 11.1) */
    public const NAME_MAX = 50;

    /** 指示文の長さの上限(文字。値の理由 = docs の 設計/AI/設定とプロンプト/README.md 11.1) */
    public const INSTRUCTIONS_MAX = 10000;

    public function __construct(
        private AgentTools $tools,
        private RecordModuleDirectory $directory,
        private DefinitionToolSize $size,
    ) {}

    /** @return array<string, list<string>> 欄 → 断る理由 */
    public function of(AgentDefinitionInput $input): array
    {
        $problems = array_filter([
            'name' => $this->name($input->name),
            'instructions' => mb_strlen($input->instructions) > self::INSTRUCTIONS_MAX
                ? ['指示文は '.self::INSTRUCTIONS_MAX.' 文字までです']
                : [],
            'tools' => $this->tools($input->tools),
            'knowledge' => $this->knowledge($input->knowledge),
        ]);

        $listed = ! isset($problems['tools']) && ! isset($problems['knowledge']);

        if ($listed && $this->size->exceeds(self::draft($input))) {
            $problems['tools'] = ['使えるツールとナレッジの業務モジュールが多すぎます。減らしてください'];
        }

        return $problems;
    }

    /** @return list<string> */
    private function name(string $name): array
    {
        return match (true) {
            trim($name) === '' => ['名前を入れてください'],
            mb_strlen($name) > self::NAME_MAX => ['名前は '.self::NAME_MAX.' 文字までです'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function tools(array $names): array
    {
        $missing = array_values(array_filter($names, fn (string $name): bool => $this->tools->named($name) === null));

        return $missing === [] ? [] : ['登録に無いツールです: '.implode('、', $missing)];
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function knowledge(array $codes): array
    {
        $modules = array_column($this->directory->topLevelModules(), 'code');
        $missing = array_values(array_filter($codes, fn (string $code): bool => ! in_array($code, $modules, true)));
        $stopped = array_values(array_filter(
            array_diff($codes, $missing),
            fn (string $code): bool => $this->directory->visibilityStopReason($code) !== null,
        ));

        return [
            ...($missing === [] ? [] : ['無い業務モジュールか明細の業務モジュールです: '.implode('、', $missing)]),
            ...($stopped === [] ? [] : ['表示を止めている業務モジュールです: '.implode('、', $stopped)]),
        ];
    }

    /** 大きさを測る保存の前の定義(保存しない) */
    private static function draft(AgentDefinitionInput $input): AiAgentDefinition
    {
        return new AiAgentDefinition(['tools' => $input->tools, 'knowledge' => $input->knowledge]);
    }
}
