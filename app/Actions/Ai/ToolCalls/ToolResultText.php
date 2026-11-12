<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\RecordMarker;
use App\Enums\Ai\AiInstructionTemplate;
use App\Queries\Ai\Api\RecordMarkers;

/**
 * 道具の結果の全文と、上限を当てた本文・定型・値(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.7)。上限を当てるのは包みの
 * 1 か所だけにし、全文は道具の行に残す(9.4 の 6)。値と先頭の長さは Claude Code の値にそろえる。
 *
 * @phpstan-type Shaped array{full: string, template: AiInstructionTemplate|null, values: array<string, string>,
 *     body: string|null}
 */
final class ToolResultText
{
    /** 1 つの結果の既定の上限(道具が大きい値を答えても、この値で頭打ちにする) */
    public const DEFAULT_LIMIT = 50_000;

    /** 上限を超えたときに返す先頭の長さ */
    public const PREVIEW_LENGTH = 2_000;

    /** 先頭の中の最後の改行がこの位置より後ろなら、その改行で切る */
    public const PREVIEW_BREAK_AFTER = 1_000;

    /**
     * 結果を全文と、保存する定型・値・本文に分ける(道具の名前は包みが直してから入れる)。
     *
     * @return Shaped
     */
    public static function shaped(AgentTool $tool, AgentToolResult $result, string $toolName): array
    {
        $full = self::full($result);

        if ($result->data === []) {
            return ['full' => $full, 'template' => AiInstructionTemplate::EmptyResult, 'values' => [
                'tool_name' => $toolName,
            ], 'body' => null];
        }

        $limit = min($tool->maxResultLength() ?? self::DEFAULT_LIMIT, self::DEFAULT_LIMIT);
        $preview = self::preview($full, $limit);

        if ($preview === null) {
            return ['full' => $full, 'template' => null, 'values' => [], 'body' => $full];
        }

        return ['full' => $full, 'template' => $tool->largeResultTemplate(), 'values' => [
            'original_length' => (string) mb_strlen($full),
            'preview_length' => (string) mb_strlen($preview),
        ], 'body' => $preview];
    }

    /** 上限を超えた文の先頭(超えなければ null) */
    public static function preview(string $text, int $limit): ?string
    {
        if (mb_strlen($text) <= $limit) {
            return null;
        }

        $preview = mb_substr($text, 0, self::PREVIEW_LENGTH);
        $lastBreak = mb_strrpos($preview, "\n");

        return $lastBreak !== false && $lastBreak > self::PREVIEW_BREAK_AFTER
            ? mb_substr($preview, 0, $lastBreak)
            : $preview;
    }

    /** 結果の値を直し、印を付けた JSON(整形しない) */
    public static function full(AgentToolResult $result): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        return json_encode(self::marked($result->data), $flags);
    }

    private static function marked(mixed $value): mixed
    {
        if ($value instanceof RecordMarker) {
            return RecordMarkers::of($value->module, $value->id);
        }

        if (is_string($value)) {
            return RecordMarkers::neutralize($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $marked = [];

        foreach ($value as $key => $item) {
            $marked[is_string($key) ? RecordMarkers::neutralize($key) : $key] = self::marked($item);
        }

        return $marked;
    }
}
