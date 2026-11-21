<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;

/**
 * 1 回の送信の受け取る物(docs の 設計/AI/会話の中核の口と受け手.md 13.3)。
 * 1 回の送信の種別は題だけなので、種別・流すか(題は流さない)・接続先のキャッシュに書くか(題は書かない)は受け取らない(13.3)。
 */
final readonly class SendRequest
{
    /**
     * @param  string  $instructions  指示の文
     * @param  list<ConversationItem|TransientText>  $history  送る履歴(会話の保存の項目と中核の中の値の型の列 = 13.2)
     * @param  list<Tool>  $tools  渡す道具(laravel/ai の道具の約束の形。中核は動かさない = 13.1)
     * @param  array<string, Type>|null  $schema  構造化の出力の形
     * @param  array<string, mixed>  $providerOptions  要求に足す指定(繋ぎが混ぜた物。中核は開けずに渡す)
     * @param  int|null  $budgetMs  題の持ち時間(ミリ秒。無ければ null = 設計/AI/エージェントの実行/途中の表示と上限.md 9.10)
     */
    public function __construct(
        public SendTarget $target,
        public string $instructions,
        public array $history,
        public array $tools,
        public ?array $schema,
        public array $providerOptions,
        public ?int $budgetMs,
        public SendReceivers $receivers,
    ) {}
}
