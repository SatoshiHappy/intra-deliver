<?php

namespace App\Actions\Ai\Core\Substitute;

/**
 * 代わりの版の送る前の対の直し(規則の正本 = docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.8 の「送る前の対の直し」)。結果を呼び出しの直後に
 * 並べ直すのは、接続先が呼び出しと結果の並びを求めるため。
 *
 * @phpstan-import-type SentBlock from SentMessage
 */
final class SentPairs
{
    /**
     * @param  list<SentMessage>  $messages
     * @param  string  $missing  結果の無い呼び出しに付ける失敗の結果の文
     * @return list<SentMessage>
     */
    public static function paired(array $messages, string $missing): array
    {
        $results = self::results($messages);
        $seen = [];
        $out = [];

        foreach ($messages as $message) {
            if (! $message->fromModel) {
                $rest = self::withoutResults($message);

                if ($rest !== null) {
                    $out[] = $rest;
                }

                continue;
            }

            [$model, $calls] = self::uniqueCalls($message, $seen);
            $out[] = $model;

            if ($calls !== []) {
                $out[] = new SentMessage(false, self::resultsFor($calls, $results, $missing));
            }
        }

        return $out;
    }

    /**
     * 結果の文の引き(呼び出しの id → 結果。同じ id の結果は最初の物)。
     *
     * @param  list<SentMessage>  $messages
     * @return array<string, SentBlock>
     */
    private static function results(array $messages): array
    {
        $results = [];

        foreach ($messages as $message) {
            foreach ($message->blocks as $block) {
                if ($block['type'] === 'tool_result' && ! isset($results[$block['call_id']])) {
                    $results[$block['call_id']] = $block;
                }
            }
        }

        return $results;
    }

    /**
     * 前の呼び出しと id が重なる呼び出しを外したモデルの文と、残した呼び出しの id(外したら生の状態も外す = 設計/AI/会話の中核.md 13.2)。
     *
     * @param  array<string, true>  $seen
     * @return array{SentMessage, list<string>}
     */
    private static function uniqueCalls(SentMessage $message, array &$seen): array
    {
        $blocks = [];
        $calls = [];

        foreach ($message->blocks as $block) {
            if ($block['type'] === 'tool_call' && isset($seen[$block['id']])) {
                continue;
            }

            if ($block['type'] === 'tool_call') {
                $seen[$block['id']] = true;
                $calls[] = $block['id'];
            }

            $blocks[] = $block;
        }

        $changed = count($blocks) !== count($message->blocks);

        return [$changed ? $message->withBlocks($blocks)->withReplay(null) : $message, $calls];
    }

    /**
     * @param  list<string>  $calls
     * @param  array<string, SentBlock>  $results
     * @return list<SentBlock>
     */
    private static function resultsFor(array $calls, array $results, string $missing): array
    {
        return array_map(
            static fn (string $id): array => $results[$id]
                ?? ['type' => 'tool_result', 'call_id' => $id, 'text' => $missing, 'is_error' => true],
            $calls,
        );
    }

    /** 利用者の文から結果を外した物(結果はモデルの文の後に置き直す。文が残らなければ無し) */
    private static function withoutResults(SentMessage $message): ?SentMessage
    {
        $blocks = array_values(array_filter(
            $message->blocks,
            static fn (array $block): bool => $block['type'] !== 'tool_result',
        ));

        return $blocks === [] ? null : $message->withBlocks($blocks);
    }
}
