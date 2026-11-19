<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use App\Actions\Ai\Core\Substitute\SentMessage;
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * 送る形のメッセージを laravel/ai のメッセージに写す(版ごとの変換の 1 か所 = docs の 設計/AI/会話の中核.md 13.1)。道具の結果には、
 * その呼び出しの名前と引数と、結果を結ぶ id(あれば)を付ける。
 */
final class SubstituteMessages
{
    /**
     * @param  list<SentMessage>  $messages
     * @return list<Message>
     */
    public static function of(array $messages, string $providerName): array
    {
        $calls = [];
        $out = [];

        foreach ($messages as $message) {
            if ($message->fromModel) {
                $out[] = self::model($message, $providerName, $calls);

                continue;
            }

            array_push($out, ...self::user($message, $calls));
        }

        return $out;
    }

    /**
     * @param  array<string, ToolCall>  $calls
     */
    private static function model(SentMessage $message, string $providerName, array &$calls): AssistantMessage
    {
        $texts = [];
        $toolCalls = [];

        foreach ($message->blocks as $block) {
            if ($block['type'] === 'text') {
                $texts[] = $block['text'];
            }

            if ($block['type'] === 'tool_call') {
                $call = new ToolCall($block['id'], $block['name'], $block['arguments'], $block['result_id']);
                $calls[$block['id']] = $call;
                $toolCalls[] = $call;
            }
        }

        $replay = $message->replay ?? [];

        return new AssistantMessage(
            implode("\n", $texts),
            new Collection($toolCalls),
            $replay,
            $replay === [] ? null : $providerName,
        );
    }

    /**
     * @param  array<string, ToolCall>  $calls
     * @return list<Message>
     */
    private static function user(SentMessage $message, array $calls): array
    {
        $results = [];
        $texts = [];

        foreach ($message->blocks as $block) {
            if ($block['type'] === 'tool_result') {
                $call = $calls[$block['call_id']] ?? null;
                $results[] = new ToolResult(
                    $block['call_id'],
                    $call->name ?? '',
                    $call->arguments ?? [],
                    $block['text'],
                    $call?->resultId,
                    failed: $block['is_error'],
                );
            }

            if ($block['type'] === 'text') {
                $texts[] = $block['text'];
            }
        }

        $out = $results === [] ? [] : [new ToolResultMessage(new Collection($results))];

        return $texts === [] ? $out : [...$out, new UserMessage(implode("\n", $texts))];
    }
}
