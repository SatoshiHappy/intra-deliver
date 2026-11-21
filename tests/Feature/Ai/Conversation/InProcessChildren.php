<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\ToolCallChild;
use App\Actions\Ai\Conversation\ToolCallChildInput;
use App\Actions\Ai\Conversation\ToolChildRunner;
use Closure;

/**
 * 繋ぎのテストの子の処理: 入力を JSON で往復させてから同じプロセスで子の中身を通す(テストの DB の区間の外からは書いた行が見えないため)。
 * 落とす呼び出しは出力を null にし、落とす前の処理(行を処理中にする・権限を外す)を差し込める。
 */
final class InProcessChildren implements ToolChildRunner
{
    /** @var array<string, Closure(ToolCallChildInput): void> 呼び出しの id → 落とす前の処理(当たる呼び出しは失敗にする) */
    public array $failing = [];

    /** @var Closure(): void|null 子を起こす前の処理 */
    public ?Closure $before = null;

    /** @var list<int> 受けた同時に動かす数 */
    public array $concurrencies = [];

    public function run(array $inputs, int $concurrency): array
    {
        $this->concurrencies[] = $concurrency;

        if ($this->before !== null) {
            ($this->before)();
        }

        return array_map(function (ToolCallChildInput $input): ?array {
            $copied = ToolCallChildInput::fromJson($input->toJson());

            if (isset($this->failing[$copied->call->id])) {
                ($this->failing[$copied->call->id])($copied);

                return null;
            }

            return app(ToolCallChild::class)->run($copied);
        }, $inputs);
    }
}
