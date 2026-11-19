<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\Substitute\Sdk\SubstituteGateway;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteInput;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteMessages;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * 材料から 1 回の送信を組む: 送る文の全体の字数(数える範囲 = docs の 設計/AI/会話の中核の口と受け手.md 13.3。生の状態を渡す応答は
 * 写しの長さ)・送る量の見積もり(字数 × 係数 = {@see SubstituteAmounts::estimate()})・SDK に渡す物・送った要求の原文の写し。
 */
final class SubstitutePlans
{
    public static function of(SubstituteParts $parts): SubstitutePlan
    {
        $chars = self::length($parts->system) + $parts->toolChars
            + array_sum(array_map(self::messageChars(...), $parts->messages));
        $estimate = SubstituteAmounts::estimate($chars, $parts->target->traits->estimateFactor);
        $input = new SubstituteInput(
            SubstituteGateway::of($parts->target->provider),
            $parts->target->provider,
            $parts->target->model->id,
            $parts->system,
            SubstituteMessages::of($parts->messages, $parts->target->providerName),
            $parts->tools,
            $parts->schema,
            self::options($parts),
            $parts->target->timeoutSeconds,
            $parts->stream,
        );

        $limit = $parts->sendLimit ?? $estimate;

        return new SubstitutePlan($parts->kind, $input, $chars, $estimate, $limit, self::record($parts));
    }

    /**
     * 道具の名前の並び。
     *
     * @param  list<Tool>  $tools
     * @return list<string>
     */
    public static function toolNames(array $tools): array
    {
        return array_map(static fn (Tool $tool): string => ToolNameResolver::resolve($tool), $tools);
    }

    private static function options(SubstituteParts $parts): TextGenerationOptions
    {
        $caches = $parts->target->traits->cachesInstructions && $parts->writesCache;

        return new TextGenerationOptions(
            maxTokens: $parts->maxTokens,
            cacheInstructions: $caches ? new CacheInstructions : null,
            cacheToolDefinitions: $caches && $parts->tools !== [] ? new CacheToolDefinitions : null,
            providerOptions: $parts->providerOptions,
        );
    }

    /**
     * @param  list<Tool>  $tools
     */
    public static function toolChars(array $tools): int
    {
        $chars = 0;

        foreach ($tools as $tool) {
            $types = $tool->schema(new JsonSchemaTypeFactory);
            $schema = array_map(static fn (Type $type): array => $type->toArray(), $types);
            $chars += self::length(ToolNameResolver::resolve($tool).$tool->description()
                .json_encode($schema, JSON_UNESCAPED_UNICODE));
        }

        return $chars;
    }

    private static function messageChars(SentMessage $message): int
    {
        if ($message->replay !== null) {
            return self::length((string) json_encode($message->replay, JSON_UNESCAPED_UNICODE));
        }

        return array_sum(array_map(static fn (array $block): int => match ($block['type']) {
            'text' => self::length($block['text']),
            'tool_call' => self::length($block['name'].json_encode($block['arguments'], JSON_UNESCAPED_UNICODE)),
            'tool_result' => self::length($block['text']),
        }, $message->blocks));
    }

    private static function length(string $text): int
    {
        return mb_strlen($text);
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(SubstituteParts $parts): array
    {
        return [
            'kind' => $parts->kind->value,
            'model' => $parts->target->model->id,
            'max_tokens' => $parts->maxTokens,
            'system' => $parts->system,
            'tools' => self::toolNames($parts->tools),
            'messages' => array_map(static fn (SentMessage $message): array => [
                'role' => $message->fromModel ? 'assistant' : 'user',
                'blocks' => $message->blocks,
                'replayed' => $message->replay !== null,
            ], $parts->messages),
        ];
    }
}
