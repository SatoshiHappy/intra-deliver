<?php

namespace App\Actions\Ai\ToolCalls;

/**
 * モデルが返した道具の呼び出し 1 つ(確定したブロックの呼び出しの id・道具の名前・引数。どれもモデルが返したまま = docs の 設計/AI/実行の記録.md 2.4)。
 * 呼び出しの id は空でない(空の id に値を振るのはブロックが確定する所)。
 */
final readonly class ModelToolCall
{
    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function __construct(public string $id, public string $name, public array $arguments) {}
}
