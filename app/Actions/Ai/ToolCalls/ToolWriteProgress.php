<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\PriorToolCall;

/**
 * 書く時の部品が 1 つの答えの実行の中で積む物(前に問うになった件・作った件の仮の名前と id)。後の件のやり直しと前の件を指す
 * 印に使う(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の件どうしのつながり)。
 */
final class ToolWriteProgress
{
    /** @var list<PriorToolCall> 同じステップで前に問うになった件(引数は書く値の引数) */
    public array $prior = [];

    /** @var array<string, int> 書けた作るの件の仮の名前 → 作ったレコードの id */
    public array $created = [];

    /**
     * 書けた作るの件の仮の名前を覚える(仮の名前の無い件と、直すの件は覚えない)。
     *
     * @param  array<string, mixed>  $written
     * @param  list<array{module: string, id: int, name: string}>  $records
     */
    public function createdBy(AgentRecordTool $tool, array $written, array $records): void
    {
        $ref = $written[ToolCallLinks::REF] ?? null;

        if ($tool instanceof AgentCreateTool && is_string($ref) && isset($records[0])) {
            $this->created[$ref] = $records[0]['id'];
        }
    }
}
