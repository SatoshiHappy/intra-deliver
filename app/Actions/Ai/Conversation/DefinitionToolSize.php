<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\ToolCalls\AgentTools;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\ToolRanges;
use App\Actions\Ai\ToolCalls\ToolScopes;
use App\Models\Ai\AiAgentDefinition;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Records\Api\RecordModuleDirectory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;

/**
 * 定義の道具の定義の大きさの確かめ(docs の 設計/AI/設定とプロンプト/README.md 11.3)。依頼者の権限で絞らずに測る(どの依頼者でも上限の内にするため)。
 *
 * @phpstan-type Scoped array{name: string, definition: array<string, mixed>}
 */
final readonly class DefinitionToolSize
{
    /** 上限の、会話の用途のモデルの読める量に対する割合(%。道具の定義に使うのを読める量の 1 割までにする) */
    public const PERCENT_OF_CONTEXT = 10;

    public function __construct(
        private AgentTools $tools,
        private RecordModuleDirectory $directory,
        private InstructionBuilder $instructions,
        private ConversationSettings $settings,
        private ProviderTraitsOf $traits,
    ) {}

    /** 上限を超えるか */
    public function exceeds(AiAgentDefinition $definition): bool
    {
        return $this->tokens($definition) > $this->limit();
    }

    /** 送る量の見積もり(トークン。字数 × 接続先とモデルの係数) */
    public function tokens(AiAgentDefinition $definition): int
    {
        $purpose = $this->settings->purpose(ConversationSettings::CONVERSATION);
        $factor = $this->traits->of($purpose['provider'], $purpose['model'])->estimateFactor;

        return (int) ceil($this->chars($definition) * $factor);
    }

    /** 道具の名前・説明・形の JSON の字数(送る前の確かめの見積もりと同じ数え方) */
    public function chars(AiAgentDefinition $definition): int
    {
        $chars = 0;

        foreach ($this->granted($definition) as $granted) {
            $types = $granted->tool->schema(new JsonSchemaTypeFactory);
            $schema = array_map(static fn (Type $type): array => $type->toArray(), $types);
            $chars += mb_strlen($granted->tool->name().$granted->tool->description($granted->scope)
                .json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return $chars;
    }

    /** 上限(トークン) */
    public function limit(): int
    {
        return intdiv($this->settings->contextWindowTokens() * self::PERCENT_OF_CONTEXT, 100);
    }

    /**
     * 測る道具の名前と範囲の業務モジュールの名前と定義(渡す道具の型でなく読むだけの形で返す。測る道具は依頼者の権限で絞らず参照制限の既定も
     * 埋めた物なので、ループに渡せる形で外に出さない)。
     *
     * @return list<array{string, array<string, Scoped>, array<string, Scoped>}> 道具の名前・modules・shown
     */
    public function scopes(AiAgentDefinition $definition): array
    {
        return array_map(
            static fn (GrantedTool $tool): array => [$tool->tool->name(), $tool->scope->modules, $tool->scope->shown],
            $this->granted($definition),
        );
    }

    /**
     * 測る道具(外した後の定義の道具と範囲。依頼者の権限で絞らない)。参照制限の既定は偽で埋める(既定は確認の内容と書く値にだけ効き、
     * 道具の定義に入らないので大きさに効かない)。
     *
     * @return list<GrantedTool>
     */
    private function granted(AiAgentDefinition $definition): array
    {
        $names = array_column($this->directory->topLevelModules(), 'name', 'code');
        $stops = [];
        $open = function (string $code) use ($names, &$stops): bool {
            if (! isset($names[$code])) {
                return false;
            }

            $stops[$code] ??= [$this->directory->visibilityStopReason($code)];

            return $stops[$code][0] === null;
        };
        $ranges = ToolRanges::of($this->tools, $definition, $open);
        $codes = array_values(array_unique(array_merge(...array_map(
            static fn (array $range): array => $range[1] ?? [],
            $ranges,
        ))));
        $definitions = $this->definitions($codes);
        $defaults = array_fill_keys(array_keys($definitions), false);

        return ToolScopes::of($ranges, $names, $definitions, $defaults, $this->instructions->conversationMarkerForms());
    }

    /**
     * 業務モジュールの定義(依頼者によらない読み取り。読んだ後に消えた業務モジュールは入れない)。
     *
     * @param  list<string>  $codes
     * @return array<string, array<string, mixed>>
     */
    private function definitions(array $codes): array
    {
        $definitions = [];

        foreach ($codes as $code) {
            try {
                $definitions[$code] = $this->directory->definitionUnchecked($code);
            } catch (ModelNotFoundException) {
                continue;
            }
        }

        return $definitions;
    }
}
