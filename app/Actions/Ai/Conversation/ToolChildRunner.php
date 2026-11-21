<?php

namespace App\Actions\Ai\Conversation;

/**
 * 並べて動かす組の子の処理を起こす口(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4。本物 = 別のプロセス {@see ToolCallChildren})。
 */
interface ToolChildRunner
{
    /**
     * @param  list<ToolCallChildInput>  $inputs  呼び出しの順
     * @param  positive-int  $concurrency  同時に動かす数の上限
     * @return list<array<string, mixed>|null> 入力の順の子の出力(失敗した子は null)
     */
    public function run(array $inputs, int $concurrency): array;
}
