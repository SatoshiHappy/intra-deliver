<?php

namespace App\Actions\Ai\Core;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;

/**
 * 呼び出しを組に分ける中核の約束の部品(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の並べて動かす。両方の版が呼ぶ)。並べてよい道具の
 * 呼び出しの続きを 1 つの組にし、渡していない道具・並べてよくない道具・引数の形が通らない呼び出しは 1 つずつの組にして前後の組を切る。
 * 並べてよい呼び出しが 1 つだけの組も並べない組にする(結果は同じで、子の処理の起動を省く)。
 */
final class ToolGroups
{
    /**
     * @param  list<CoreToolCall>  $calls  呼び出しの順
     * @param  array<string, Tool>  $tools  渡す道具(送る名前 → 道具。名前は版が SDK の引き方で引く)
     * @param  array<string, bool>  $parallel  送る名前 → 並べてよいか(無い名前は並べない)
     * @return list<ToolGroup>
     */
    public static function of(array $calls, array $tools, array $parallel): array
    {
        $groups = [];

        foreach ($calls as $call) {
            $safe = self::safe($call, $tools[$call->name] ?? null, $parallel[$call->name] ?? false);
            $last = array_key_last($groups);

            if ($safe && $last !== null && $groups[$last]->parallel) {
                $groups[$last] = new ToolGroup(true, [...$groups[$last]->calls, $call]);
            } else {
                $groups[] = new ToolGroup($safe, [$call]);
            }
        }

        // 呼び出しが 1 つの組は並べずに動かす(結果は同じで、子の処理の起動を省く)
        return array_map(
            static fn (ToolGroup $group): ToolGroup => count($group->calls) > 1
                ? $group
                : new ToolGroup(false, $group->calls),
            $groups,
        );
    }

    private static function safe(CoreToolCall $call, ?Tool $tool, bool $parallel): bool
    {
        if ($tool === null || ! $parallel) {
            return false;
        }

        $schema = ToolArguments::objectSchema($tool->schema(new JsonSchemaTypeFactory));

        return ToolArguments::problems($schema, $call->arguments) === [];
    }
}
